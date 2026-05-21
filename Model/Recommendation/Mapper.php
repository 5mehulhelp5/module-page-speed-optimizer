<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Recommendation;

/**
 * Maps PageSpeed Insights audit IDs → which ETechFlow feature fixes them.
 *
 * Drives the "ETechFlow can fix this!" badge next to each PSI recommendation
 * in the admin. Lets a merchant see Google's complaint AND know which one
 * of our settings would resolve it.
 *
 * Audits with no clean mapping get no badge — we're honest about scope.
 */
class Mapper
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        // Image audits — covered by ETechFlow Image Optimizer (separate module)
        'uses-webp-images'          => 'ETechFlow Image Optimizer: Enable WebP conversion',
        'modern-image-formats'      => 'ETechFlow Image Optimizer: Enable WebP conversion',
        'uses-optimized-images'     => 'ETechFlow Image Optimizer: Run etechflow:pso:optimize-images for lossless JPEG/PNG compression',
        'offscreen-images'          => 'ETechFlow Image Optimizer: Enable native lazy-loading',
        'unsized-images'            => 'Magento product images carry width/height by default — check theme overrides',
        'uses-responsive-images'    => 'ETechFlow Image Optimizer v1.4+ — per-attribute image sizing',

        // Code optimization — coming in PSO v1.1
        'unminified-css'            => 'Coming in PSO v1.1 — CSS minification',
        'unminified-javascript'     => 'Coming in PSO v1.1 — JavaScript minification',
        'render-blocking-resources' => 'Coming in PSO v1.1 — Critical CSS extraction + defer JS',
        'unused-css-rules'          => 'Coming in PSO v1.1 — Critical CSS extraction',
        'unused-javascript'         => 'Coming in PSO v1.1 — JS tree-shaking + defer',
        'uses-text-compression'     => 'Enable GZIP/Brotli at the server — PSO v1.1 will ship .htaccess + nginx snippets',

        // Font + LCP audits — coming in PSO v1.1
        'font-display'                       => 'Coming in PSO v1.1 — Defer Fonts Loading',
        'preload-fonts'                      => 'Coming in PSO v1.1 — Prioritize Resource Loading',
        'critical-request-chains'            => 'Coming in PSO v1.1 — Critical CSS + preload',
        'largest-contentful-paint-element'   => 'Mostly addressed by image optimization (IO) + critical CSS (PSO v1.1)',
    ];

    public function getFix(string $auditId): ?string
    {
        return self::MAP[$auditId] ?? null;
    }
}
