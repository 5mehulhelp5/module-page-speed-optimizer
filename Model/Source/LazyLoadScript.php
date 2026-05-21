<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the lazy-load strategy admin field.
 *
 * Matches Amasty's 4-script lineup:
 *   - native:  `<img loading="lazy">` — browser-native. Default. Zero JS.
 *   - vanilla: vanillajs-lazyload (~3KB minified). Older browsers + IntersectionObserver polyfill.
 *   - lozad:   lozad.js (~1KB). Tiny IntersectionObserver wrapper.
 *   - jquery:  jquery.lazy.js. Older themes that still use jQuery.
 *
 * Native is always the right default in 2026 (98%+ browser support).
 * Other options preserved for compatibility with themes that expect them.
 */
class LazyLoadScript implements OptionSourceInterface
{
    public const SCRIPT_NATIVE  = 'native';
    public const SCRIPT_VANILLA = 'vanilla';
    public const SCRIPT_LOZAD   = 'lozad';
    public const SCRIPT_JQUERY  = 'jquery';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::SCRIPT_NATIVE,
             'label' => __('Native browser (loading="lazy") — recommended, no JS overhead')],
            ['value' => self::SCRIPT_VANILLA,
             'label' => __('Vanilla JS Lazy — ~3KB, IntersectionObserver-based')],
            ['value' => self::SCRIPT_LOZAD,
             'label' => __('Lozad.js — ~1KB, minimal IntersectionObserver wrapper')],
            ['value' => self::SCRIPT_JQUERY,
             'label' => __('jQuery Lazy — for older themes still using jQuery')],
        ];
    }
}
