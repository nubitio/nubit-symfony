<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

use Attribute;

/**
 * Marks a line entity for the generic embedded-lines reload endpoint served by
 * nubitio/admin-bundle ({@code GET /api/...?parent={id}}). The React
 * formDetail grid uses the URL to load existing rows when editing a parent.
 *
 * The parent document PATCH/POST still submits lines embedded under
 * {@code propertyName} — this attribute only replaces custom reload controllers.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EmbeddedLines
{
    public function __construct(
        /** Doctrine association on the line pointing to the parent (e.g. "document"). */
        public string $parentProperty,
        /**
         * Query parameter substituted for {@code {id}} in formDetail URLs.
         * Defaults to {@see parentProperty}.
         */
        public ?string $parentQueryParam = null,
        /**
         * Collection route path. When omitted, derived from the table name
         * ({@code sales_document_line} → {@code /api/sales_document_lines}).
         */
        public ?string $route = null,
        /** Serializer groups used when building the plain JSON rows. */
        public array $normalizationGroups = [],
    ) {
    }
}