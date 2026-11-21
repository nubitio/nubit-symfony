<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final class XlsWorkbookBuilder
{
    /**
     * @var list<XlsSheetSpec>
     */
    private array $sheets = [];

    public function __construct(
        private string $creator = 'Nubit',
        private string $title = 'Nubit export',
    ) {}

    public static function create(?string $title = null): self
    {
        return new self(title: $title ?? 'Nubit export');
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @param array<string, string|array<string, mixed>|XlsColumnSpec> $columns
     * @param list<string>|null $fields
     */
    public function sheet(
        string $title,
        iterable $rows,
        array $columns = [],
        ?array $fields = null,
        ?XlsSheetOptions $options = null,
    ): self {
        $this->sheets[] = new XlsSheetSpec(
            rows: $rows,
            columns: $columns,
            fields: $fields,
            options: $options ?? new XlsSheetOptions(title: $title),
        );

        return $this;
    }

    public function build(): XlsWorkbookSpec
    {
        return new XlsWorkbookSpec(sheets: $this->sheets, creator: $this->creator, title: $this->title);
    }
}
