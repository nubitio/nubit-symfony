<?php

declare(strict_types=1);

namespace Nubit\Platform\Feature\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class RequiresFeature
{
    public function __construct(
        public readonly string $featureKey,
        public readonly string $message = 'This feature requires a plan upgrade.',
    ) {
    }
}
