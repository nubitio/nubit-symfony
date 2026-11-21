<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsColumnOptions
{
    public function __construct(
        public string $label,
        public ?string $type = null,
        public XlsColumnPresentation $presentation = new XlsColumnPresentation(),
        public XlsColumnSummary $summary = new XlsColumnSummary(),
    ) {}
}
