<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Attribute;

use Attribute;

/**
 * Maps a Doctrine entity to a quota resource name enforced on {@code prePersist}.
 *
 * Limits come from {@see \Nubit\Platform\Feature\Contract\FeatureCheckerInterface::getFeatureConfig()}
 * under the {@code max} key for the same resource/feature name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class QuotaResource
{
    public function __construct(
        public string $resource,
    ) {
    }
}