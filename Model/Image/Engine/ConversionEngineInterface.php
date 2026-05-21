<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

/**
 * Conversion engine contract.
 *
 * Three implementations: CwebpEngine (binary), ImagickEngine (PHP ext),
 * GdEngine (PHP ext). EngineChain picks the first one available based
 * on the admin-configured priority order.
 *
 * Why three: hosting heterogeneity. Some hosts have cwebp installed,
 * some have Imagick, some have GD, some have all three. We try the
 * fastest available and fall back gracefully.
 */
interface ConversionEngineInterface
{
    /**
     * Engine name used in admin config + log table. Lowercase, short.
     */
    public function getName(): string;

    /**
     * Is this engine usable on the current server? Cheap check — should
     * NOT actually convert anything. Used by EngineChain to skip unusable
     * engines without touching disk.
     */
    public function available(): bool;

    /**
     * Convert a single image to WebP. Writes output next to the source
     * (same name, `.webp` extension). Returns true on success, throws on
     * failure with a useful message for the log table.
     *
     * @param string $sourcePath Absolute filesystem path to source image.
     * @param string $outputPath Absolute filesystem path where the .webp should be written.
     * @param int    $quality    1-100. Engines that don't support quality (rare) MAY ignore.
     * @throws \RuntimeException
     */
    public function convertToWebp(string $sourcePath, string $outputPath, int $quality): bool;
}
