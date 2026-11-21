<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsColumnSummary
{
    public function __construct(
        public ?string $type = null,
        public ?string $formula = null,
        public ?string $label = null,
    ) {}
}
