<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

/**
 * Uses ImageMagick's PHP extension. Available where the host has both
 * the imagick PHP extension AND ImageMagick compiled with WebP support.
 *
 * Pros over GD: better quality output, supports more source formats.
 * Cons: heavier memory use, slower than cwebp binary.
 *
 * Detect availability with: php -r 'echo extension_loaded("imagick");'
 *   AND verify "WEBP" is in the supported-format list.
 */
class ImagickEngine implements ConversionEngineInterface
{
    public function getName(): string
    {
        return 'imagick';
    }

    public function available(): bool
    {
        if (!\extension_loaded('imagick')) {
            return false;
        }
        if (!\class_exists(\Imagick::class)) {
            return false;
        }
        try {
            $formats = \Imagick::queryFormats('WEBP');
            return !empty($formats);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function convertToWebp(string $sourcePath, string $outputPath, int $quality): bool
    {
        if (!\extension_loaded('imagick')) {
            throw new \RuntimeException('Imagick extension not loaded');
        }
        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('Imagick: source not readable: %s', $sourcePath));
        }
        $quality = max(1, min(100, $quality));

        $imagick = new \Imagick();
        try {
            $imagick->readImage($sourcePath);
            // Strip EXIF / colour profiles — typically not useful for WebP
            // variants of product images and adds 1-5KB per file.
            $imagick->stripImage();
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);
            // method=4 is the speed/size sweet-spot, same as cwebp default.
            $imagick->setOption('webp:method', '4');
            if (!$imagick->writeImage($outputPath)) {
                throw new \RuntimeException(sprintf('Imagick: writeImage failed: %s', $outputPath));
            }
        } catch (\ImagickException $e) {
            throw new \RuntimeException(sprintf('Imagick: %s', $e->getMessage()), 0, $e);
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
        return true;
    }
}
