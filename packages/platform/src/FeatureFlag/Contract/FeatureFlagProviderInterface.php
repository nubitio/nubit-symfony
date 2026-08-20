<?php

declare(strict_types=1);

namespace Nubit\Platform\FeatureFlag\Contract;

use Nubit\Platform\FeatureFlag\FeatureFlagContext;

/** Vendor-neutral feature flag evaluation port, designed for OpenFeature adapters. */
interface FeatureFlagProviderInterface
{
    public function boolean(string $key, bool $default, FeatureFlagContext $context): bool;

    public function string(string $key, string $default, FeatureFlagContext $context): string;

    public function integer(string $key, int $default, FeatureFlagContext $context): int;

    public function float(string $key, float $default, FeatureFlagContext $context): float;

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function object(string $key, array $default, FeatureFlagContext $context): array;
}
