<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * No-op release marker for v2.3.1 — first Packagist release.
 *
 * Establishes the always-a-patch discipline adopted across all ETechFlow
 * modules after the NDE v1.7.0 Keystation deploy incident: every release
 * ships at least one data patch so `setup:upgrade` always has something
 * to register in `patch_list`, surfacing FS / permissions / DI errors
 * during the patch phase (which retries cleanly) instead of at the end
 * of the upgrade (which doesn't).
 *
 * Going forward, every release of this module ships at least one patch.
 * If a release has no real data migration to do, this template gets
 * copied/renamed (`V232ReleaseMarker`, `V300ReleaseMarker`, etc).
 */
class V231ReleaseMarker implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        return $this;
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getDependencies(): array
    {
        return [];
    }
}
