<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\UserAgent;

use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * Lightweight server-side device classifier.
 *
 * Returns one of: 'mobile' | 'tablet' | 'desktop' based on the User-Agent
 * header. No 3rd-party library dependency (mobiledetect/mobile-detect is
 * popular but adds ~50KB and is overkill for our 3-bucket classification).
 *
 * Patterns derived from Magento Core's own internal classification + the
 * IETF "structured-headers" recommendations.
 *
 * Cached per-request: User-Agent doesn't change mid-request, so we
 * resolve once and store.
 */
class DeviceDetector
{
    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_TABLET  = 'tablet';
    public const DEVICE_MOBILE  = 'mobile';

    private ?string $cachedDevice = null;

    public function __construct(
        private readonly HttpRequest $request
    ) {
    }

    public function getDevice(): string
    {
        if ($this->cachedDevice !== null) {
            return $this->cachedDevice;
        }
        $ua = (string) $this->request->getServer('HTTP_USER_AGENT', '');
        return $this->cachedDevice = $this->classify($ua);
    }

    public function isMobile(): bool
    {
        return $this->getDevice() === self::DEVICE_MOBILE;
    }

    public function isTablet(): bool
    {
        return $this->getDevice() === self::DEVICE_TABLET;
    }

    public function isDesktop(): bool
    {
        return $this->getDevice() === self::DEVICE_DESKTOP;
    }

    /**
     * Pure UA classification — no side effects, easy to unit-test.
     */
    public function classify(string $userAgent): string
    {
        if ($userAgent === '') {
            return self::DEVICE_DESKTOP;
        }
        $lower = strtolower($userAgent);

        // Tablet detection FIRST — iPads identify as Macintosh on iOS 13+
        // (Safari "Request Desktop Site" default) so we check for known
        // tablet substrings before mobile.
        $tabletPatterns = [
            'ipad', 'tablet', 'kindle', 'silk', 'playbook', 'sm-t',
            'galaxy tab', 'nexus 7', 'nexus 9', 'nexus 10',
            'sch-i800', 'kfapwi', 'kffowi', 'kfsawi', 'kfthwi',
        ];
        foreach ($tabletPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return self::DEVICE_TABLET;
            }
        }
        // iPad on iOS 13+ identifies as Mac — check for touch + Mac combo
        if (str_contains($lower, 'macintosh') && str_contains($lower, 'mobile')) {
            return self::DEVICE_TABLET;
        }

        $mobilePatterns = [
            'mobile', 'android', 'iphone', 'ipod', 'phone', 'blackberry',
            'bb10', 'opera mini', 'opera mobi', 'iemobile', 'webos',
            'windows phone', 'silk-accelerated', 'fennec', 'minimo',
            'palm os', 'palmsource',
        ];
        foreach ($mobilePatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return self::DEVICE_MOBILE;
            }
        }

        return self::DEVICE_DESKTOP;
    }
}
