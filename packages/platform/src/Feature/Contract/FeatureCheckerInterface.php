<?php

declare(strict_types=1);

namespace Nubit\Platform\Feature\Contract;

interface FeatureCheckerInterface
{
    /**
     * Returns true if the current tenant's active plan (or an active override)
     * has the given feature enabled. Returns false if no tenant context exists
     * or the plan cannot be resolved (fail-closed).
     */
    public function hasFeature(string $featureKey): bool;

    /**
     * Returns the config array for a feature (e.g. ['days' => 365]).
     * Returns empty array if feature is disabled or has no config.
     *
     * @return array<string, mixed>
     */
    public function getFeatureConfig(string $featureKey): array;

    /**
     * Throws FeatureNotAvailableException (HTTP 402) if the feature is not enabled.
     * Use in controllers or services that require a feature to proceed.
     */
    public function requireFeature(string $featureKey): void;

    /**
     * Returns all feature keys that are currently enabled for the active tenant.
     * Useful for the /api/tenant/entitlements endpoint.
     *
     * @return array<string, array{enabled: bool, config: array<string, mixed>}>
     */
    public function getEntitlements(): array;
}
