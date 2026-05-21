<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Walks the configured image directories, hands each file to
 * WebpGenerator, returns aggregated counts.
 *
 * Used by:
 *   - bin/magento etechflow:pso:optimize-images (CLI batch)
 *   - Cron consumer (incremental nightly run; v1.1)
 *
 * Cheap on the "everything already converted" path — generator dedupes
 * via mtime check before touching the engine.
 */
class Processor
{
    public function __construct(
        private readonly Config $config,
        private readonly WebpGenerator $generator,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * Walk the configured paths and convert. Returns a counts array:
     *   ['scanned' => N, 'converted' => N, 'skipped' => N, 'failed' => N]
     *
     * @param int|null $limit Max files to process this run (null = no cap).
     * @param callable|null $onTick Called after each file with the current
     *                              counts array. Used by the CLI progress bar.
     */
    public function process(?int $limit = null, ?callable $onTick = null): array
    {
        $span = Profiler::start('ETechFlow_PSO_ImageProcessor');
        $counts = ['scanned' => 0, 'converted' => 0, 'skipped' => 0, 'failed' => 0];
        try {
            $paths = $this->resolveTargetPaths();
            foreach ($paths as $path) {
                if (!is_dir($path)) {
                    continue;
                }
                foreach ($this->walkImages($path) as $file) {
                    $counts['scanned']++;
                    $result = $this->generator->generate($file);
                    if ($result === WebpGenerator::RESULT_CONVERTED) {
                        $counts['converted']++;
                    } elseif ($result === WebpGenerator::RESULT_FAILED) {
                        $counts['failed']++;
                    } else {
                        $counts['skipped']++;
                    }
                    if ($onTick !== null) {
                        $onTick($counts);
                    }
                    if ($limit !== null && $counts['scanned'] >= $limit) {
                        return $counts;
                    }
                }
            }
            return $counts;
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * @return string[] Absolute paths to scan, derived from admin config toggles.
     */
    private function resolveTargetPaths(): array
    {
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        $paths = [];
        if ($this->config->isProductCacheCovered()) {
            $paths[] = rtrim($mediaDir, '/') . '/catalog/product/cache';
            // Also walk the original product image dir — without /cache —
            // so newly uploaded images get a WebP sibling before Magento
            // generates a cache variant. Magento's image renderer pre-
            // checks for `.webp` next to the source.
            $paths[] = rtrim($mediaDir, '/') . '/catalog/product';
        }
        if ($this->config->isCategoryCovered()) {
            $paths[] = rtrim($mediaDir, '/') . '/catalog/category';
        }
        if ($this->config->isCmsCovered()) {
            $paths[] = rtrim($mediaDir, '/') . '/wysiwyg';
        }
        return $paths;
    }

    /**
     * Generator that yields image-file paths under a directory.
     *
     * Skips `.webp` files (already converted) and any `.htaccess` /
     * `*.tmp` / `_thumbs` rubbish.
     *
     * @return \Generator<string>
     */
    private function walkImages(string $root): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                continue;
            }
            yield $file->getPathname();
        }
    }
}
