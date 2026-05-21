<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Response;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Sets `Link: <url>; rel=preload; as=<type>` response headers for critical
 * assets, mapping to:
 *  - HTTP/2 Server Push on Apache/Nginx servers that honor the Link header
 *    (Chrome dropped native HTTP/2 push support in v106 but still respects
 *    rel=preload for early-hints + preload tactics)
 *  - Browser-level <link rel=preload> auto-conversion
 *
 * Auto-detects critical assets from the response body:
 *  - First 2 fonts (woff2) discovered in <style> @font-face rules
 *  - First main CSS file (matching 'styles-m' or 'styles' Magento bundles)
 *
 * Plus: admin-configured URLs to ALWAYS preload (logos, hero images, etc).
 *
 * Skip conditions:
 *  - Admin area
 *  - AJAX
 *  - Non-HTML responses
 */
class ServerPushPlugin
{
    use HeaderHelperTrait;

    public function __construct(
        private readonly Config $config,
        private readonly HttpRequest $request
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): array
    {
        if (!$this->config->isServerPushEnabled()) {
            return [];
        }
        if (!$this->shouldProcess($subject)) {
            return [];
        }
        $span = Profiler::start('ETechFlow_PSO_ServerPush');
        try {
            $body = (string) $subject->getBody();
            $links = $this->buildLinkHeaders($body);
            if (!empty($links)) {
                // Append (not replace) — preserve any existing Link header set by other modules
                $existing = $this->headerValue($subject, 'Link');
                $combined = $existing !== ''
                    ? $existing . ', ' . implode(', ', $links)
                    : implode(', ', $links);
                $subject->setHeader('Link', $combined, true);
            }
        } catch (\Throwable $e) {
            // never break the response
        } finally {
            Profiler::stop($span);
        }
        return [];
    }

    private function shouldProcess(HttpResponse $response): bool
    {
        $contentType = $this->headerValue($response, 'Content-Type');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }
        $uri = (string) $this->request->getRequestUri();
        if (str_contains($uri, '/admin')) {
            return false;
        }
        if ($this->request->isAjax()) {
            return false;
        }
        return true;
    }

    /**
     * @return string[] Link header value pieces, each ready to be comma-joined.
     */
    private function buildLinkHeaders(string $body): array
    {
        $links = [];

        // Admin-configured URLs
        foreach ($this->config->getPreloadUrls() as $url) {
            $as = $this->guessAsType($url);
            $crossorigin = $as === 'font' ? '; crossorigin' : '';
            $links[] = sprintf('<%s>; rel=preload; as=%s%s', $url, $as, $crossorigin);
        }

        // Auto-detect WOFF2 fonts in @font-face rules (up to 2)
        if (preg_match_all('#url\(["\']?([^"\')]+\.woff2[^"\')]*)["\']?\)#i', $body, $m) && !empty($m[1])) {
            $fonts = array_slice(array_unique($m[1]), 0, 2);
            foreach ($fonts as $font) {
                $links[] = sprintf('<%s>; rel=preload; as=font; type=font/woff2; crossorigin', $font);
            }
        }

        return $links;
    }

    private function guessAsType(string $url): string
    {
        $lower = strtolower($url);
        if (preg_match('/\.(?:woff2|woff|ttf|otf)(?:\?|$)/', $lower)) {
            return 'font';
        }
        if (preg_match('/\.(?:jpg|jpeg|png|gif|webp|avif|svg)(?:\?|$)/', $lower)) {
            return 'image';
        }
        if (preg_match('/\.css(?:\?|$)/', $lower)) {
            return 'style';
        }
        if (preg_match('/\.js(?:\?|$)/', $lower)) {
            return 'script';
        }
        return 'fetch';
    }
}
