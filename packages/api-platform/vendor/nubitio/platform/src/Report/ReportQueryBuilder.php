<?php

declare(strict_types=1);

namespace Nubit\Platform\Report;

final readonly class ReportQueryBuilder
{
    public function __construct(
        private GridFilterApplier $gridFilterApplier = new GridFilterApplier(),
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public function build(ExportableReportInterface $report, array $params, string $rawFilter = ''): ReportQuery
    {
        $gridFilter = $this->gridFilterApplier->buildSql($rawFilter, $report->fieldMap(), $params);

        return new ReportQuery(
            sql: $report->sql($gridFilter),
            params: $params,
            columns: $report->columns(),
            filename: $report->filename(),
        );
    }
}
