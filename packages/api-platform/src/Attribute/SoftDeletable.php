<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

use Attribute;

/**
 * Marks an entity for the global soft-delete filter: rows whose delete column
 * is not NULL are excluded from every query while the filter is enabled.
 *
 * Opt-in by design — having a `deletedAt` field alone is not enough, since
 * some domains (e.g. voided sales) keep soft-deleted rows visible.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SoftDeletable
{
    public function __construct(
        public string $column = 'deleted_at',
    ) {
    }
}
