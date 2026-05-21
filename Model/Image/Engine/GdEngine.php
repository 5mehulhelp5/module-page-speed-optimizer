<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

/**
 * Uses PHP's GD extension. Most universally available — PHP 7.0+ has
 * `imagewebp()` when compiled with `--with-webp`. Most Magento hosts
 * have GD with WebP since Magento itself uses GD as its default image
 * adapter.
 *
 * Pros: works almost everywhere with no extra setup.
 * Cons: slightly larger output than cwebp (~5-10%), no support for
 * very-high-resolution images (4K+) without memory tuning.
 */
class GdEngine implements ConversionEngineInterface
{
    public function getName(): string
    {
        return 'gd';
    }

    public function available(): bool
    {
        if (!\extension_loaded('gd')) {
            return false;
        }
        // imagewebp() is the actual function we'll call — its existence
        // means GD was compiled with WebP support.
        return \function_exists('imagewebp');
    }

    public function convertToWebp(string $sourcePath, string $outputPath, int $quality): bool
    {
        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('GD: source not readable: %s', $sourcePath));
        }
        $quality = max(1, min(100, $quality));

        $mime = (string) @\mime_content_type($sourcePath);
        $resource = false;
        switch ($mime) {
            case 'image/jpeg':
                $resource = @\imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $resource = @\imagecreatefrompng($sourcePath);
                if ($resource !== false) {
                    // Preserve alpha for PNGs — without this, transparency
                    // becomes solid black in the WebP.
                    \imagepalettetotruecolor($resource);
                    \imagealphablending($resource, true);
                    \imagesavealpha($resource, true);
                }
                break;
            case 'image/gif':
                $resource = @\imagecreatefromgif($sourcePath);
                break;
            default:
                throw new \RuntimeException(sprintf('GD: unsupported MIME type %s for %s', $mime, $sourcePath));
        }
        if ($resource === false) {
            throw new \RuntimeException(sprintf('GD: failed to load source image: %s', $sourcePath));
        }
        try {
            if (!\imagewebp($resource, $outputPath, $quality)) {
                throw new \RuntimeException(sprintf('GD: imagewebp() failed for %s', $outputPath));
            }
        } finally {
            \imagedestroy($resource);
        }
        return true;
    }
}
