<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsSheetLayout
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        public array $fields,
        public string $lastColumn,
        public int $lastDataRow,
        public int $totalsRow,
        public int $rowCount,
    ) {}
}
