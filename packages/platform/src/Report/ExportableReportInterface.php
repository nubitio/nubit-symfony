<?php

declare(strict_types=1);

namespace Nubit\Platform\Report;

/**
 * Contract for a single grid-filterable, XLSX-exportable report.
 *
 * Modules tag their implementations (e.g. 'app.sale_report') so a
 * per-module dispatcher can resolve the correct report by key.
 */
interface ExportableReportInterface
{
    /**
     * Unique URL-safe key used in the ?report= query parameter.
     * Example: 'detailed-sales', 'accounting-report'.
     */
    public function key(): string;

    /**
     * Maps frontend grid field names to the SQL expressions used in this report.
     * Only fields present here are accepted from the grid filter; others are ignored.
     *
     * @return array<string, string>  field → SQL expression
     */
    public function fieldMap(): array;

    /**
     * Returns the full SELECT SQL for the report.
     * $gridFilter is a pre-built 'AND ...' fragment (empty string if no active filters).
     */
    public function sql(string $gridFilter): string;

    /**
     * Column header map for the XLSX export: SQL alias → display label.
     *
     * @return array<string, string>
     */
    public function columns(): array;

    /**
     * Base filename for the XLSX download (without extension).
     */
    public function filename(): string;
}
