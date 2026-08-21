<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

use Attribute;

/**
 * Marks a resource as bulk-exportable: its collection and item endpoints
 * answer the "xlsx" format (GET /api/products.xlsx, ?_format=xlsx, or the
 * spreadsheet Accept header) once nubit_admin.export is enabled.
 *
 * Opt-in by design — an export endpoint streams every row matching the query
 * in one response, which is a far wider read than the paginated grid the same
 * user sees. Resources that never justified that (payment schedules, audit
 * rows, anything holding personal data) should not gain it just because a
 * sibling resource needed a spreadsheet.
 *
 * Without this attribute the format is removed from the resource's operations,
 * so API Platform answers 406 Not Acceptable and the OpenAPI document does not
 * advertise a format the resource will refuse.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Exportable
{
    public function __construct(
        /**
         * Restricts export to specific operation names (e.g. `['_api_/products{._format}_get_collection']`).
         * Null exports every operation the resource exposes.
         *
         * @var list<string>|null
         */
        public ?array $operations = null,
    ) {}
}
