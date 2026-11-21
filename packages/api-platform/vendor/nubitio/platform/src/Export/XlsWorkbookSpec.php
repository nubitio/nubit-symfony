<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsWorkbookSpec
{
    /**
     * @param list<XlsSheetSpec> $sheets
     */
    public function __construct(
        public array $sheets,
        public string $creator = 'Nubit',
        public string $title = 'Nubit export',
    ) {}
}
