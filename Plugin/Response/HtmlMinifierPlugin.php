<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Response;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Minifies the HTML response body before it's sent to the browser.
 *
 * Strips:
 *  - line breaks between tags  (whitespace between </x><y>)
 *  - leading whitespace at start of each line
 *  - HTML comments (except IE-conditional and script-protective <!--<![CDATA[ ... ]]>--> patterns)
 *  - tab characters
 *
 * Does NOT touch:
 *  - <pre>, <textarea>, <script>, <style> contents (preserving these is critical —
 *    minifying inside them breaks JS template literals, CSS animations, code blocks)
 *
 * Per Amasty's docs: 10-20% size reduction typical, content unchanged.
 *
 * Bypass logic:
 *  - Only minifies text/html responses
 *  - Skips admin (Magento_Backend area)
 *  - Skips AJAX responses (request is_ajax true)
 *  - Skips URLs matching the exclusion list (substring match per Amasty's behaviour)
 */
class HtmlMinifierPlugin
{
    use HeaderHelperTrait;

    /** Sentinel tokens used to protect block contents during minification. */
    private const TOKEN_PREFIX = "\x01ETF_PSO_PROTECT_";
    private const TOKEN_SUFFIX = "\x01";

    public function __construct(
        private readonly Config $config,
        private readonly HttpRequest $request
    ) {
    }

    /**
     * @param HttpResponse $subject
     * @param mixed $result
     */
    public function afterSendResponse(HttpResponse $subject, $result)
    {
        // sendResponse() echoes the body, so we can't rewrite after-the-fact.
        // Use the before-send hook instead.
        return $result;
    }

    /**
     * Hook: runs before Magento writes the response body. We rewrite the body
     * in place so the merchant's HTML reaches the browser already minified.
     */
    public function beforeSendResponse(HttpResponse $subject): array
    {
        if (!$this->config->isHtmlMinifyEnabled()) {
            return [];
        }
        if (!$this->shouldMinifyCurrentRequest($subject)) {
            return [];
        }

        $span = Profiler::start('ETechFlow_PSO_HtmlMinify');
        try {
            $body = (string) $subject->getBody();
            if ($body === '') {
                return [];
            }
            $minified = $this->minify($body);
            if ($minified !== $body) {
                $subject->setBody($minified);
            }
        } catch (\Throwable $e) {
            // Never break the response on a minification failure — silently bail.
        } finally {
            Profiler::stop($span);
        }
        return [];
    }

    private function shouldMinifyCurrentRequest(HttpResponse $response): bool
    {
        // Only text/html
        $contentType = $this->headerValue($response, 'Content-Type');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }
        // Admin area
        $uri = (string) $this->request->getRequestUri();
        if (str_contains($uri, '/admin')) {
            return false;
        }
        // AJAX
        if ($this->request->isAjax()) {
            return false;
        }
        // Exclusion patterns
        foreach ($this->config->getHtmlMinifyExcludeUrls() as $pattern) {
            if ($pattern !== '' && str_contains($uri, $pattern)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The actual minifier. Two-phase: extract preserve-blocks → token, minify
     * the remainder, restore preserve-blocks.
     */
    private function minify(string $html): string
    {
        $preserved = [];
        $tokenIdx = 0;

        // Phase 1: hide all <pre>, <textarea>, <script>, <style> contents behind opaque tokens
        $patterns = [
            '#<pre\b[^>]*>.*?</pre>#is',
            '#<textarea\b[^>]*>.*?</textarea>#is',
            '#<script\b[^>]*>.*?</script>#is',
            '#<style\b[^>]*>.*?</style>#is',
        ];
        foreach ($patterns as $pattern) {
            $html = preg_replace_callback($pattern, function ($m) use (&$preserved, &$tokenIdx) {
                $token = self::TOKEN_PREFIX . $tokenIdx . self::TOKEN_SUFFIX;
                $preserved[$token] = $m[0];
                $tokenIdx++;
                return $token;
            }, $html) ?? $html;
        }

        // Phase 2: minify
        // Strip HTML comments — but keep IE conditional comments and script-CDATA wraps
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html) ?? $html;
        // Collapse runs of whitespace between tags
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;
        // Collapse multiple spaces / tabs / newlines into a single space within text content
        $html = preg_replace('/\s{2,}/', ' ', $html) ?? $html;
        // Trim leading + trailing whitespace
        $html = trim($html);

        // Phase 3: restore preserved blocks
        foreach ($preserved as $token => $original) {
            $html = str_replace($token, $original, $html);
        }
        return $html;
    }
}
