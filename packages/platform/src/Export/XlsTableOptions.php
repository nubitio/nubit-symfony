<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsTableOptions
{
    public function __construct(
        public string $name,
        public string $style = 'TableStyleMedium2',
    ) {}
}
