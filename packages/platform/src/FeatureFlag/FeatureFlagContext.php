<?php

declare(strict_types=1);

namespace Nubit\Platform\FeatureFlag;

final readonly class FeatureFlagContext
{
    /** @param array<string, bool|int|float|string|null> $attributes */
    public function __construct(
        public ?string $targetingKey = null,
        public ?int $tenantId = null,
        public ?string $tenantName = null,
        public array $attributes = [],
    ) {
    }
}
