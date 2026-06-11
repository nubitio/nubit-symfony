<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tenant;

use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;

/** Single-tenant default: every feature is available. */
final class AllowAllFeatureChecker implements FeatureCheckerInterface
{
    public function hasFeature(string $featureKey): bool
    {
        return true;
    }

    public function getFeatureConfig(string $featureKey): array
    {
        return [];
    }

    public function requireFeature(string $featureKey): void
    {
        // Every feature is available in single-tenant mode.
    }

    public function getEntitlements(): array
    {
        return [];
    }
}
