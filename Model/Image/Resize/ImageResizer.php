<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Resize;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Image\Engine\ConversionEngineInterface;
use ETechFlow\PageSpeedOptimizer\Model\Image\Engine\EngineChain;
use ETechFlow\PageSpeedOptimizer\Model\OptimizationLog;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog as OptimizationLogResource;
use Psr\Log\LoggerInterface;

/**
 * Produces device-targeted WebP/AVIF variants at smaller resolutions.
 *
 * For each source image (e.g. foo.jpg), we emit:
 *   foo.jpg.mobile.webp   (default 480px wide — phones)
 *   foo.jpg.tablet.webp   (default 768px wide — tablets)
 * Plus AVIF variants if AVIF is enabled.
 *
 * Two algorithms:
 *   - fit:  scale proportionally, longest edge becomes target width.
 *           No cropping, full image preserved at smaller size.
 *   - crop: centered crop to target dimensions. Forces a fixed aspect
 *           ratio (we use the source aspect ratio at target width;
 *           customers wanting square thumbs use the source aspect anyway).
 *
 * Uses Imagick if available, falls back to GD. cwebp can't resize, so
 * the chain is independent — we always go through Imagick/GD for the
 * resize step, then encode to the target format via Imagick/GD's
 * native format support.
 *
 * Idempotency: skips if the variant file exists with matching mtime.
 */
class ImageResizer
{
    public const RESULT_GENERATED = 'generated';
    public const RESULT_SKIPPED   = 'skipped';
    public const RESULT_FAILED    = 'failed';

    public const ALGORITHM_FIT  = 'fit';
    public const ALGORITHM_CROP = 'crop';

    public const VARIANT_MOBILE = 'mobile';
    public const VARIANT_TABLET = 'tablet';

    private const SUPPORTED_INPUT_EXT = ['jpg', 'jpeg', 'png', 'gif'];

    public function __construct(
        private readonly Config $config,
        private readonly EngineChain $engineChain,
        private readonly OptimizationLogResource $logResource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Generate mobile + tablet variants of one source image.
     *
     * Returns RESULT_* indicating outcome. Failure on one variant doesn't
     * block the other — we attempt both independently.
     */
    public function generateVariants(string $sourcePath): string
    {
        if (!$this->config->isImageResizeEnabled()) {
            return self::RESULT_SKIPPED;
        }
        $span = Profiler::start('ETechFlow_PSO_ImageResize');
        try {
            if (!is_file($sourcePath) || !is_readable($sourcePath)) {
                return self::RESULT_SKIPPED;
            }
            $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
            if (!in_array($ext, self::SUPPORTED_INPUT_EXT, true)) {
                return self::RESULT_SKIPPED;
            }

            $anyGenerated = false;
            $anyFailed = false;

            foreach (
                [
                    self::VARIANT_MOBILE => $this->config->getMobileVariantWidth(),
                    self::VARIANT_TABLET => $this->config->getTabletVariantWidth(),
                ] as $variant => $targetWidth
            ) {
                try {
                    $r = $this->generateOneVariant($sourcePath, $variant, $targetWidth);
                    if ($r === self::RESULT_GENERATED) {
                        $anyGenerated = true;
                    } elseif ($r === self::RESULT_FAILED) {
                        $anyFailed = true;
                    }
                } catch (\Throwable $e) {
                    $anyFailed = true;
                    $this->logger->warning(
                        'ETechFlow_PSO resize variant failed',
                        ['source' => $sourcePath, 'variant' => $variant, 'exception' => $e->getMessage()]
                    );
                }
            }

            if ($anyGenerated) {
                return self::RESULT_GENERATED;
            }
            return $anyFailed ? self::RESULT_FAILED : self::RESULT_SKIPPED;
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * Variant filename: `<source>.<variant>.<format>` (e.g. foo.jpg.mobile.webp).
     */
    public function variantPathFor(string $sourcePath, string $variant, string $format): string
    {
        return sprintf('%s.%s.%s', $sourcePath, $variant, $format);
    }

    private function generateOneVariant(string $sourcePath, string $variant, int $targetWidth): string
    {
        $sourceMtime = @filemtime($sourcePath) ?: 0;
        $algorithm = $this->config->getImageResizeAlgorithm();

        // Generate WebP variant first (always available where the base
        // WebP engine chain works).
        $webpPath = $this->variantPathFor($sourcePath, $variant, 'webp');
        if (is_file($webpPath) && $this->isAlreadyLogged($sourcePath, $webpPath, $sourceMtime)) {
            return self::RESULT_SKIPPED;
        }
        if (!$this->resizeAndConvert($sourcePath, $webpPath, $targetWidth, $algorithm, 'webp')) {
            return self::RESULT_FAILED;
        }
        $this->log($sourcePath, $webpPath, $variant, $targetWidth, $algorithm, $sourceMtime, 'webp');

        // Generate AVIF variant if AVIF is enabled — silent skip if no encoder.
        if ($this->config->isAvifEnabled()) {
            $avifPath = $this->variantPathFor($sourcePath, $variant, 'avif');
            if (!is_file($avifPath) || !$this->isAlreadyLogged($sourcePath, $avifPath, $sourceMtime)) {
                try {
                    if ($this->resizeAndConvert($sourcePath, $avifPath, $targetWidth, $algorithm, 'avif')) {
                        $this->log($sourcePath, $avifPath, $variant, $targetWidth, $algorithm, $sourceMtime, 'avif');
                    }
                } catch (\Throwable $e) {
                    // AVIF failure is non-blocking — WebP variant still produced
                }
            }
        }

        return self::RESULT_GENERATED;
    }

    /**
     * Resize + re-encode via Imagick (preferred) or GD (fallback).
     */
    private function resizeAndConvert(
        string $sourcePath,
        string $outputPath,
        int $targetWidth,
        string $algorithm,
        string $format
    ): bool {
        // Try Imagick first — does both resize + format conversion in one pass
        if (\extension_loaded('imagick') && \class_exists(\Imagick::class)) {
            try {
                $this->resizeWithImagick($sourcePath, $outputPath, $targetWidth, $algorithm, $format);
                return true;
            } catch (\Throwable $e) {
                // Fall through to GD
            }
        }
        // GD fallback (WebP only — GD's AVIF is rare)
        if (\extension_loaded('gd') && \function_exists('imagewebp')) {
            $this->resizeWithGd($sourcePath, $outputPath, $targetWidth, $algorithm, $format);
            return true;
        }
        throw new \RuntimeException('No resize-capable engine available (need Imagick or GD)');
    }

    private function resizeWithImagick(
        string $sourcePath,
        string $outputPath,
        int $targetWidth,
        string $algorithm,
        string $format
    ): void {
        $imagick = new \Imagick();
        try {
            $imagick->readImage($sourcePath);
            $imagick->stripImage();
            $srcWidth = $imagick->getImageWidth();
            $srcHeight = $imagick->getImageHeight();
            if ($srcWidth <= $targetWidth) {
                // Source already smaller than target — just convert, don't upscale
                $targetHeight = $srcHeight;
            } else {
                $ratio = $srcHeight / $srcWidth;
                $targetHeight = (int) round($targetWidth * $ratio);
                if ($algorithm === self::ALGORITHM_CROP) {
                    // Centered crop to target dimensions
                    $imagick->cropThumbnailImage($targetWidth, $targetHeight);
                } else {
                    // Fit (proportional scale)
                    $imagick->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1);
                }
            }
            $imagick->setImageFormat($format);
            $imagick->setImageCompressionQuality($this->config->getQuality());
            if (!$imagick->writeImage($outputPath)) {
                throw new \RuntimeException(sprintf('Imagick writeImage failed: %s', $outputPath));
            }
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    private function resizeWithGd(
        string $sourcePath,
        string $outputPath,
        int $targetWidth,
        string $algorithm,
        string $format
    ): void {
        $mime = (string) @\mime_content_type($sourcePath);
        $source = match ($mime) {
            'image/jpeg' => @\imagecreatefromjpeg($sourcePath),
            'image/png'  => @\imagecreatefrompng($sourcePath),
            'image/gif'  => @\imagecreatefromgif($sourcePath),
            default      => false,
        };
        if (!$source) {
            throw new \RuntimeException(sprintf('GD: unsupported source MIME %s', $mime));
        }
        try {
            $srcWidth = \imagesx($source);
            $srcHeight = \imagesy($source);
            if ($srcWidth <= $targetWidth) {
                $resized = $source;
                $newWidth = $srcWidth;
                $newHeight = $srcHeight;
            } else {
                $ratio = $srcHeight / $srcWidth;
                $newWidth = $targetWidth;
                $newHeight = (int) round($targetWidth * $ratio);
                $resized = \imagecreatetruecolor($newWidth, $newHeight);
                // Preserve transparency for PNG
                if ($mime === 'image/png') {
                    \imagealphablending($resized, false);
                    \imagesavealpha($resized, true);
                    $transparent = \imagecolorallocatealpha($resized, 0, 0, 0, 127);
                    \imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
                }
                \imagecopyresampled(
                    $resized, $source,
                    0, 0, 0, 0,
                    $newWidth, $newHeight,
                    $srcWidth, $srcHeight
                );
            }

            if ($format === 'webp') {
                if (!\imagewebp($resized, $outputPath, $this->config->getQuality())) {
                    throw new \RuntimeException(sprintf('GD: imagewebp failed for %s', $outputPath));
                }
            } elseif ($format === 'avif' && \function_exists('imageavif')) {
                if (!\imageavif($resized, $outputPath, $this->config->getQuality())) {
                    throw new \RuntimeException(sprintf('GD: imageavif failed for %s', $outputPath));
                }
            } else {
                throw new \RuntimeException(sprintf('GD: format %s not supported', $format));
            }
        } finally {
            \imagedestroy($source);
            if (isset($resized) && $resized !== $source) {
                \imagedestroy($resized);
            }
        }
    }

    private function isAlreadyLogged(string $sourcePath, string $outputPath, int $sourceMtime): bool
    {
        $connection = $this->logResource->getConnection();
        $select = $connection->select()
            ->from($this->logResource->getMainTable(), ['source_mtime'])
            ->where('source_path = ?', $sourcePath)
            ->where('output_path = ?', $outputPath)
            ->where('status = ?', OptimizationLog::STATUS_OK)
            ->limit(1);
        $logged = $connection->fetchOne($select);
        return $logged !== false && (int) $logged === $sourceMtime;
    }

    private function log(
        string $sourcePath,
        string $outputPath,
        string $variant,
        int $targetWidth,
        string $algorithm,
        int $sourceMtime,
        string $format
    ): void {
        $bytesBefore = (int) (@filesize($sourcePath) ?: 0);
        $bytesAfter = (int) (@filesize($outputPath) ?: 0);
        $savings = $bytesBefore > 0
            ? (int) round((($bytesBefore - $bytesAfter) * 100) / $bytesBefore)
            : 0;
        $sourceExt = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));

        $this->logResource->getConnection()->insertOnDuplicate(
            $this->logResource->getMainTable(),
            [
                'source_path'   => $sourcePath,
                'output_path'   => $outputPath,
                'format_from'   => $sourceExt === 'jpg' ? 'jpeg' : $sourceExt,
                'format_to'     => $format,
                'bytes_before'  => $bytesBefore,
                'bytes_after'   => $bytesAfter,
                'savings_pct'   => $savings,
                'engine'        => sprintf('resize-%s-%dw-%s', $variant, $targetWidth, $algorithm),
                'source_mtime'  => $sourceMtime,
                'status'        => OptimizationLog::STATUS_OK,
                'error_message' => null,
                'optimized_at'  => date('Y-m-d H:i:s'),
            ],
            ['format_from', 'format_to', 'bytes_before', 'bytes_after',
             'savings_pct', 'engine', 'source_mtime', 'status',
             'error_message', 'optimized_at']
        );
    }
}
