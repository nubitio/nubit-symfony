<?php

declare(strict_types=1);

namespace Nubit\Platform\FeatureFlag;

use Nubit\Platform\FeatureFlag\Contract\FeatureFlagProviderInterface;

/** Deterministic provider for local configuration, tests and safe defaults. */
final readonly class StaticFeatureFlagProvider implements FeatureFlagProviderInterface
{
    /** @param array<string, mixed> $flags */
    public function __construct(private array $flags = [])
    {
    }

    public function boolean(string $key, bool $default, FeatureFlagContext $context): bool
    {
        return is_bool($this->flags[$key] ?? null) ? $this->flags[$key] : $default;
    }

    public function string(string $key, string $default, FeatureFlagContext $context): string
    {
        return is_string($this->flags[$key] ?? null) ? $this->flags[$key] : $default;
    }

    public function integer(string $key, int $default, FeatureFlagContext $context): int
    {
        return is_int($this->flags[$key] ?? null) ? $this->flags[$key] : $default;
    }

    public function float(string $key, float $default, FeatureFlagContext $context): float
    {
        return is_float($this->flags[$key] ?? null) ? $this->flags[$key] : $default;
    }

    public function object(string $key, array $default, FeatureFlagContext $context): array
    {
        return is_array($this->flags[$key] ?? null)
            ? self::stringKeyed($this->flags[$key])
            : $default;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $normalized = array_combine(
            array_map(static fn (int|string $key): string => (string) $key, array_keys($value)),
            array_values($value),
        );

        return $normalized;
    }
}
