<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Data;

/**
 * Value object — one diagnostic run's result.
 *
 * Carries:
 *   - Lab data (Lighthouse score 0-100, FCP, LCP, TBT, CLS)
 *   - Field data (CrUX — real-user metrics, null if URL lacks Chrome user traffic)
 *   - List of failed audits as Recommendation[]
 *   - Raw JSON for storage / future re-parsing
 */
final class DiagnosticResult
{
    /**
     * @param Recommendation[] $recommendations
     */
    public function __construct(
        public readonly string $url,
        public readonly string $strategy,        // 'mobile' | 'desktop'
        public readonly int $performanceScore,   // 0-100, or -1 if failed
        public readonly ?float $labFcpSeconds,
        public readonly ?float $labLcpSeconds,
        public readonly ?float $labTbtMillis,
        public readonly ?float $labClsScore,
        public readonly ?float $fieldLcpMillis,
        public readonly ?float $fieldInpMillis,
        public readonly ?float $fieldClsScore,
        public readonly ?string $fieldOverallCategory,
        public readonly array $recommendations,
        public readonly ?string $rawJson = null,
        public readonly ?string $errorMessage = null
    ) {
    }

    public function failed(): bool
    {
        return $this->errorMessage !== null;
    }

    /**
     * Colour bands match Google's own: green ≥ 90, orange 50-89, red < 50.
     */
    public function scoreCategory(): string
    {
        if ($this->performanceScore < 0) {
            return 'unknown';
        }
        if ($this->performanceScore >= 90) {
            return 'good';
        }
        if ($this->performanceScore >= 50) {
            return 'needs-improvement';
        }
        return 'poor';
    }

    public function hasFieldData(): bool
    {
        return $this->fieldLcpMillis !== null
            || $this->fieldInpMillis !== null
            || $this->fieldClsScore !== null;
    }
}
