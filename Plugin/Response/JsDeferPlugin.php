<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Response;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Adds `defer` attribute to non-inline `<script>` tags in the response.
 *
 * Defer tells the browser: "fetch this script in parallel with HTML parsing,
 * but only execute it after the DOM is built." This dramatically reduces
 * render-blocking time and improves Time-to-Interactive.
 *
 * Why this works for Magento:
 *  - RequireJS bootstraps via inline <script>, which we DON'T touch
 *  - Magento UI components attach via DOMContentLoaded — defer is safe
 *  - jQuery + jQuery-UI load order is preserved (defer maintains document order)
 *
 * Safe-by-default: only adds defer to scripts that have BOTH `src` AND no
 * existing `async`/`defer`/`type=module` attribute (modules are deferred
 * natively).
 *
 * Skips:
 *  - URLs in the exclusion list (checkout/cart by default)
 *  - Admin area
 *  - AJAX responses
 *  - Already-deferred scripts
 *
 * NOTE: this is a runtime rewrite plugin. For higher-performance, build-time
 * static-content rewriting could be added in v2.1+; this approach works
 * universally without a setup:static-content:deploy step.
 */
class JsDeferPlugin
{
    use HeaderHelperTrait;

    public function __construct(
        private readonly Config $config,
        private readonly HttpRequest $request
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): array
    {
        if (!$this->config->isJsDeferEnabled()) {
            return [];
        }
        if (!$this->shouldProcessRequest($subject)) {
            return [];
        }
        $span = Profiler::start('ETechFlow_PSO_JsDefer');
        try {
            $body = (string) $subject->getBody();
            if ($body === '') {
                return [];
            }
            $rewritten = $this->addDeferAttribute($body);
            if ($rewritten !== $body) {
                $subject->setBody($rewritten);
            }
        } catch (\Throwable $e) {
            // never break the page
        } finally {
            Profiler::stop($span);
        }
        return [];
    }

    private function shouldProcessRequest(HttpResponse $response): bool
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
        foreach ($this->config->getJsDeferExcludeUrls() as $pattern) {
            if ($pattern !== '' && str_contains($uri, $pattern)) {
                return false;
            }
        }
        return true;
    }

    private function addDeferAttribute(string $html): string
    {
        return preg_replace_callback(
            '#<script\b([^>]*)>#i',
            function ($m) {
                $attrs = $m[1];
                // Skip inline scripts (no src)
                if (!preg_match('/\bsrc\s*=/i', $attrs)) {
                    return $m[0];
                }
                // Skip if already deferred / async / module
                if (preg_match('/\b(defer|async)\b/i', $attrs)) {
                    return $m[0];
                }
                if (preg_match('/\btype\s*=\s*["\']module["\']/i', $attrs)) {
                    return $m[0];
                }
                // Skip RequireJS itself — it bootstraps everything else
                if (preg_match('/require(?:js)?(?:\.min)?\.js/i', $attrs)) {
                    return $m[0];
                }
                // Add defer
                return '<script' . $attrs . ' defer>';
            },
            $html
        ) ?? $html;
    }
}
