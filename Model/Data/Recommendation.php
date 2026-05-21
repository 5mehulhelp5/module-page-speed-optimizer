<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Data;

/**
 * One failed audit from PSI — what Lighthouse calls an "opportunity" or
 * "diagnostic" entry.
 */
final class Recommendation
{
    public function __construct(
        public readonly string $auditId,         // e.g. "uses-webp-images"
        public readonly string $title,           // e.g. "Serve images in next-gen formats"
        public readonly string $description,     // PSI's expansion text (HTML — display as-is)
        public readonly float $impactSeconds,    // Lighthouse's estimated savings, may be 0
        public readonly ?string $etechflowFix    // ETechFlow setting that fixes this audit, or null
    ) {
    }

    public function impactBucket(): string
    {
        if ($this->impactSeconds >= 1.0) {
            return 'high';
        }
        if ($this->impactSeconds >= 0.3) {
            return 'medium';
        }
        return 'low';
    }
}
