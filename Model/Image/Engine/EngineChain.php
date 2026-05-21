<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

use ETechFlow\PageSpeedOptimizer\Model\Config;

/**
 * Picks the first available engine from the admin-configured order.
 *
 * Engine availability is cached per request — checking `cwebp` via
 * exec() takes ~5ms and we call this many times per CLI run.
 */
class EngineChain
{
    /** @var array<string, ConversionEngineInterface> */
    private array $registry;

    /** @var array<string, bool> */
    private array $availabilityCache = [];

    public function __construct(
        private readonly Config $config,
        CwebpEngine $cwebp,
        ImagickEngine $imagick,
        GdEngine $gd
    ) {
        $this->registry = [
            $cwebp->getName()   => $cwebp,
            $imagick->getName() => $imagick,
            $gd->getName()      => $gd,
        ];
    }

    /**
     * Returns the first available engine per admin config, or null if
     * NONE of the configured engines are available (server has no WebP
     * support at all — surfaced as an explicit error to the merchant
     * via verify CLI).
     */
    public function getFirstAvailable(): ?ConversionEngineInterface
    {
        foreach ($this->config->getEngineOrder() as $name) {
            if (!isset($this->registry[$name])) {
                continue;
            }
            if ($this->isAvailable($name)) {
                return $this->registry[$name];
            }
        }
        return null;
    }

    /**
     * For verify CLI: report each known engine's availability so the
     * merchant can see exactly which paths are open + which need a
     * server-side fix.
     *
     * @return array<string, bool>
     */
    public function getAvailabilityReport(): array
    {
        $report = [];
        foreach ($this->registry as $name => $engine) {
            $report[$name] = $this->isAvailable($name);
        }
        return $report;
    }

    private function isAvailable(string $name): bool
    {
        if (!isset($this->availabilityCache[$name])) {
            $this->availabilityCache[$name] = $this->registry[$name]->available();
        }
        return $this->availabilityCache[$name];
    }
}
