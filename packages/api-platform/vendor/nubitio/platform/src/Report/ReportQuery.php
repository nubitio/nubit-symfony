<?php

declare(strict_types=1);

namespace Nubit\Platform\Report;

use Nubit\Platform\Export\XlsColumnSpec;

final readonly class ReportQuery
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, string|array<string, mixed>|XlsColumnSpec> $columns
     */
    public function __construct(
        public string $sql,
        public array $params,
        public array $columns,
        public string $filename,
    ) {}
}
