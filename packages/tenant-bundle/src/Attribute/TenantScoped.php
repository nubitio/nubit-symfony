<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Attribute;

use Attribute;

/**
 * Marks an entity as belonging to the active tenant. When {@code nubit_tenant.enabled}
 * is false the attribute is ignored — apps can ship the same entities for internal
 * and SaaS profiles.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TenantScoped
{
    public function __construct(
        /** Column storing the tenant foreign key (default {@code tenant_id}). */
        public string $field = 'tenant_id',
        /** Stamp the field on prePersist when empty. */
        public bool $stampOnPersist = true,
        /**
         * Association property to stamp from {@see TenantContext} (e.g. {@code restaurant}
         * → {@code setRestaurant($em->getReference(...))}). Requires {@code tenant_entity}.
         */
        public ?string $relation = null,
    ) {
    }
}