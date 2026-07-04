<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsSheetOptions
{
    public function __construct(
        public ?string $title = null,
        public bool $freezeHeader = true,
        public bool $autoFilter = true,
        public bool $showTotals = true,
        public ?XlsTableOptions $table = null,
    ) {}
}
