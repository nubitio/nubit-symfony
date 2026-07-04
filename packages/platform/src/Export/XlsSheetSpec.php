<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsSheetSpec
{
    /**
     * @param iterable<array<string, mixed>> $rows
     * @param array<string, string|array<string, mixed>|XlsColumnSpec> $columns
     * @param list<string>|null $fields
     */
    public function __construct(
        public iterable $rows,
        public array $columns = [],
        public ?array $fields = null,
        public XlsSheetOptions $options = new XlsSheetOptions(),
    ) {}
}
