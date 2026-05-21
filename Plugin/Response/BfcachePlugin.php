<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Response;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Back/Forward Cache (bfcache) helper.
 *
 * Chrome + Firefox give pages instant back/forward navigation IF the
 * response does NOT carry `Cache-Control: no-store`. Magento's default
 * Cart and Checkout pages set `no-store` for good reason (private data).
 *
 * This plugin:
 *  - For included URLs (everything except the exclusion list): REMOVES
 *    `no-store` from Cache-Control if present, leaving the page bfcache-eligible.
 *  - For excluded URLs (checkout, cart, customer/account by default):
 *    ENFORCES `no-store` (defensive — should already be set).
 *
 * The improvement over Amasty: we explicitly enforce no-store on excluded
 * pages, so the merchant doesn't accidentally leak private data into bfcache.
 *
 * Per https://web.dev/articles/bfcache — bfcache navigation can be 100x
 * faster than a fresh load because the page comes back from memory with
 * JS state intact. Single biggest perceived-perf win in Chrome.
 */
class BfcachePlugin
{
    use HeaderHelperTrait;

    public function __construct(
        private readonly Config $config,
        private readonly HttpRequest $request
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): array
    {
        if (!$this->config->isBfcacheEnabled()) {
            return [];
        }
        $uri = (string) $this->request->getRequestUri();

        if ($this->isExcluded($uri)) {
            // Defensive: enforce no-store on private pages
            $existing = $this->headerValue($subject, 'Cache-Control');
            if (!str_contains(strtolower($existing), 'no-store')) {
                $newValue = $existing !== '' ? $existing . ', no-store' : 'no-store';
                $subject->setHeader('Cache-Control', $newValue, true);
            }
            return [];
        }

        // Included page: strip no-store from Cache-Control if Magento set it.
        // We don't add a Cache-Control header if one doesn't exist (FPC handles caching).
        $existing = $this->headerValue($subject, 'Cache-Control');
        if ($existing === '' || !str_contains(strtolower($existing), 'no-store')) {
            return [];
        }
        // Remove the `no-store` directive (with surrounding commas/whitespace)
        $cleaned = preg_replace('/\s*,?\s*no-store\s*,?/i', ',', $existing) ?? $existing;
        $cleaned = trim($cleaned, ', ');
        $cleaned = preg_replace('/,\s*,/', ',', $cleaned) ?? $cleaned;
        if ($cleaned === '') {
            // If the only directive was no-store, set a safe default
            $cleaned = 'private, max-age=0';
        }
        $subject->setHeader('Cache-Control', $cleaned, true);
        return [];
    }

    private function isExcluded(string $uri): bool
    {
        foreach ($this->config->getBfcacheExcludeUrls() as $pattern) {
            if ($pattern !== '' && str_contains($uri, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
