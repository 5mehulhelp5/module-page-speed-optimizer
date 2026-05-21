<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Catalog\Block;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use ETechFlow\PageSpeedOptimizer\Model\Image\WebpGenerator;
use Magento\Catalog\Block\Product\Image;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Wraps Magento's product image `<img>` output in a `<picture>` element
 * with a WebP source so WebP-capable browsers grab the smaller file.
 *
 * Hyvä compatibility: Magento\Catalog\Block\Product\Image is the SAME
 * block used by both Luma and Hyvä product templates — Hyvä just re-
 * skins the HTML wrapper around it. So this plugin works on both.
 *
 * The lazy-loading attribute is also injected here in one pass.
 *
 * The transformation:
 *   <img src=".../foo.jpg" alt="..." />
 * →
 *   <picture>
 *     <source srcset=".../foo.jpg.webp" type="image/webp">
 *     <img src=".../foo.jpg" alt="..." loading="lazy">
 *   </picture>
 *
 * Defensive: if anything goes wrong (regex doesn't match, WebP file
 * doesn't exist), pass through the original HTML unchanged so a bug
 * here never breaks a PDP.
 */
class PictureBlockPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly WebpGenerator $webpGenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * after-plugin on toHtml(). Result is the rendered HTML string for
     * the block (typically: `<img src="..." alt="..." width="..."
     * height="..." />`).
     *
     * @param Image $subject
     * @param mixed $result Whatever toHtml() returned (string in normal Magento,
     *                      but plugins from other modules may legally return
     *                      different types — we defensively check).
     * @return mixed Original $result if anything doesn't apply; otherwise the
     *               transformed HTML.
     */
    public function afterToHtml(Image $subject, $result)
    {
        // Defensive: only transform string results. Other plugins in the
        // chain may legally return null/false. Pass non-strings through.
        if (!is_string($result) || $result === '') {
            return $result;
        }

        if (!$this->config->isEnabled()) {
            return $result;
        }

        $span = Profiler::start('ETechFlow_PSO_PictureBlock');
        try {
            return $this->transform($result);
        } catch (\Throwable $e) {
            // Never break the PDP. Log + return original.
            $this->logger->warning(
                'ETechFlow_IO PictureBlockPlugin suppressed exception',
                ['exception' => $e->getMessage()]
            );
            return $result;
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * Pure-string transform: parse out the <img> src, check for a sibling
     * .webp, wrap in <picture> if found. Adds loading="lazy" inline.
     */
    private function transform(string $html): string
    {
        // Extract the <img ...> tag. Magento's renderer always emits a
        // single self-closing img per Image block.
        if (!preg_match('/<img\b[^>]*\bsrc\s*=\s*"([^"]+)"[^>]*\/?>/i', $html, $m)) {
            return $html;
        }
        $imgTag = $m[0];
        $srcUrl = $m[1];

        // Build the WebP URL from the source URL. The WebP lives next to
        // the source as `<source>.webp` — same path, just `.webp` suffixed.
        // Skip if source is already a WebP or external URL.
        $lower = strtolower($srcUrl);
        if (str_ends_with($lower, '.webp')
            || str_starts_with($lower, 'data:')
            || (!str_starts_with($lower, '/') && !str_contains($lower, '://'))) {
            return $html;
        }
        $webpUrl = $srcUrl . '.webp';

        // Confirm the WebP actually exists on disk before promising it
        // to the browser. Pulls the relative path under pub/ and checks
        // if the corresponding file exists.
        if (!$this->webpFileExists($webpUrl)) {
            // No WebP yet — still inject lazy-load + return.
            return $this->injectLazyLoad($html, $imgTag);
        }

        // Inject loading="lazy" if the img doesn't already have one + the
        // admin enabled lazy loading.
        $wrappedImg = $this->injectLazyLoadAttribute($imgTag);

        // Build the <picture> wrapper.
        $picture = sprintf(
            '<picture><source srcset="%s" type="image/webp">%s</picture>',
            $this->escapeAttribute($webpUrl),
            $wrappedImg
        );
        return str_replace($imgTag, $picture, $html);
    }

    /**
     * For the "no WebP yet" path: just inject lazy-load on the existing
     * img, don't wrap in picture.
     */
    private function injectLazyLoad(string $html, string $imgTag): string
    {
        if (!$this->config->isLazyLoadEnabled()) {
            return $html;
        }
        $newImg = $this->injectLazyLoadAttribute($imgTag);
        if ($newImg === $imgTag) {
            return $html;
        }
        return str_replace($imgTag, $newImg, $html);
    }

    /**
     * Add loading="lazy" to an <img> tag if it doesn't already have a
     * loading attribute and the admin enabled lazy loading.
     */
    private function injectLazyLoadAttribute(string $imgTag): string
    {
        if (!$this->config->isLazyLoadEnabled()) {
            return $imgTag;
        }
        // Already has a loading attribute? Leave it alone.
        if (preg_match('/\bloading\s*=/i', $imgTag)) {
            return $imgTag;
        }
        // Insert before the closing /> or >
        return (string) preg_replace('/(\s*\/?>)$/', ' loading="lazy"$1', $imgTag, 1);
    }

    /**
     * Does the WebP file exist on disk?
     *
     * Translates a URL like `https://.../pub/media/.../foo.jpg.webp` (or a
     * relative `/media/.../foo.jpg.webp`) into an absolute filesystem path
     * under DirectoryList::PUB.
     */
    private function webpFileExists(string $webpUrl): bool
    {
        // Strip the base URL host to get the URI part if it's an absolute URL.
        $parts = parse_url($webpUrl);
        $path = $parts['path'] ?? $webpUrl;
        if (!is_string($path) || $path === '') {
            return false;
        }
        // Map /media/... or /pub/media/... to the absolute pub path.
        try {
            $pubDir = $this->filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath();
        } catch (\Throwable $e) {
            return false;
        }
        // Common Magento URL shapes:
        //   /pub/media/...  (developer mode)
        //   /media/...      (production with pub as docroot)
        $relative = ltrim($path, '/');
        $candidates = [
            rtrim($pubDir, '/') . '/' . $relative,
            rtrim($pubDir, '/') . '/' . preg_replace('#^(pub/)?#', '', $relative),
            rtrim($pubDir, '/') . '/media/' . preg_replace('#^(pub/)?media/#', '', $relative),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }
        return false;
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
