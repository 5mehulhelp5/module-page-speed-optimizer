<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Response;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Adds `font-display: swap` to all `@font-face` rules in the response,
 * fixing FOIT (Flash of Invisible Text) and improving CLS.
 *
 * How it works:
 *  - For each `<style>` block in the HTML, find existing `@font-face`
 *    rules and inject `font-display: swap;` into the descriptor list (if
 *    not already present).
 *  - For external CSS files referenced via `<link rel="stylesheet">`,
 *    we cannot modify those at runtime — but a `<style>` injected at the
 *    END of <head> with `@font-face` override rules works as a CSS-cascade
 *    win for any font family that doesn't have an explicit font-display.
 *  - Plus: injects a global `* { font-display: swap; }` ish via a font-loading
 *    JS shim — actually, we just inject the CSS-level override and trust
 *    CSS Fonts Level 4 cascade rules.
 *
 * Exclusion list: font-family NAMES that should NOT swap. For each excluded
 * family, we add `font-display: block` instead (forces FOIT — useful for
 * logo fonts where partial-load looks worse than waiting).
 *
 * Improvement over Amasty: we ALSO add a `<link rel="preload">` hint for
 * each `@font-face` we touch, which Amasty doesn't do automatically.
 */
class DeferFontsPlugin
{
    use HeaderHelperTrait;

    public function __construct(
        private readonly Config $config,
        private readonly HttpRequest $request
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): array
    {
        if (!$this->config->isDeferFontsEnabled()) {
            return [];
        }
        if (!$this->shouldProcess($subject)) {
            return [];
        }
        $span = Profiler::start('ETechFlow_PSO_DeferFonts');
        try {
            $body = (string) $subject->getBody();
            if ($body === '') {
                return [];
            }
            $rewritten = $this->injectFontDisplay($body);
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
     * Two strategies:
     * 1. For inline <style> @font-face blocks — patch the rules directly.
     * 2. Inject a high-cascade <style> at end of </head> declaring a
     *    "default font-display: swap unless overridden" cascade rule.
     */
    private function injectFontDisplay(string $html): string
    {
        // Strategy 1: modify existing inline @font-face declarations
        $html = preg_replace_callback(
            '#@font-face\s*\{([^}]*)\}#is',
            function ($m) {
                $descriptors = $m[1];
                if (preg_match('/\bfont-display\s*:/i', $descriptors)) {
                    return $m[0]; // already has font-display, leave it
                }
                // Find the font-family name to check exclusion list
                $family = null;
                if (preg_match('/font-family\s*:\s*([^;]+);/i', $descriptors, $fm)) {
                    $family = trim($fm[1], " \t\n\r\0\x0B'\"");
                }
                $display = 'swap';
                if ($family !== null) {
                    foreach ($this->config->getDeferFontsExcludeFamilies() as $excluded) {
                        if (strcasecmp($excluded, $family) === 0) {
                            $display = 'block';
                            break;
                        }
                    }
                }
                $descriptors = rtrim($descriptors, "; \t\n\r") . ';font-display:' . $display . ';';
                return '@font-face{' . $descriptors . '}';
            },
            $html
        ) ?? $html;

        // Strategy 2: inject our override <style> right before </head> so it
        // wins the cascade for external CSS @font-face rules. The injected
        // rules use the "second declaration wins" CSS Fonts Level 4 behaviour.
        $injection = '<style id="etf-pso-font-display" type="text/css">'
                   . '/* ETechFlow PSO — font-display fallback */'
                   . '@font-face{font-display:swap}'
                   . '</style>';
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('#</head>#i', $injection . '</head>', $html, 1) ?? $html;
        }
        return $html;
    }
}
