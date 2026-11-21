<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class XlsWorksheetWriter
{
    public function __construct(
        private readonly XlsColumnResolver $columnResolver = new XlsColumnResolver(),
        private readonly XlsCellWriter $cellWriter = new XlsCellWriter(),
        private readonly XlsSpreadsheetStyler $styler = new XlsSpreadsheetStyler(),
        private readonly XlsTotalsWriter $totalsWriter = new XlsTotalsWriter(),
    ) {}

    /**
     * @throws Exception
     */
    public function write(Worksheet $sheet, XlsSheetSpec $sheetSpec): void
    {
        $this->applySheetTitle($sheet, $sheetSpec->options->title);

        [$data, $fields] = $this->collectRowsAndFields($sheetSpec->rows, $sheetSpec->fields);

        if ($fields === []) {
            $sheet->setCellValue('A1', 'No data');
            return;
        }

        $layout = new XlsSheetLayout(
            fields: $fields,
            lastColumn: Coordinate::stringFromColumnIndex(count($fields)),
            lastDataRow: count($data) + 1,
            totalsRow: count($data) + 2,
            rowCount: count($data),
        );

        $columns = $this->columnResolver->resolve($fields, $data, $sheetSpec->columns);
        $this->writeHeaders($sheet, $fields, $columns);
        $this->writeRows($sheet, $data, $fields, $columns);

        $showTotals = $sheetSpec->options->showTotals && $this->hasTotals($fields, $columns);
        if ($showTotals) {
            $this->totalsWriter->write($sheet, $layout, $columns);
        }

        $this->styler->apply($sheet, $layout, $columns, $sheetSpec->options->withTotals($showTotals));
    }

    /**
     * @param list<string> $fields
     * @param array<string, XlsColumn> $columns
     */
    private function writeHeaders(Worksheet $sheet, array $fields, array $columns): void
    {
        foreach ($fields as $index => $field) {
            $sheet->setCellValue([$index + 1, 1], $columns[$field]->label);
        }
    }

    /**
     * @param list<array<string, mixed>> $data
     * @param list<string> $fields
     * @param array<string, XlsColumn> $columns
     */
    private function writeRows(Worksheet $sheet, array $data, array $fields, array $columns): void
    {
        foreach ($data as $rowIndex => $row) {
            $excelRow = $rowIndex + 2;
            foreach ($fields as $fieldIndex => $field) {
                $this->cellWriter->write($sheet, $fieldIndex + 1, $excelRow, $row[$field] ?? null, $columns[$field]);
            }
        }
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @param list<string>|null $configuredFields
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function collectRowsAndFields(iterable $rows, ?array $configuredFields): array
    {
        $data = [];
        $fields = $configuredFields;

        foreach ($rows as $row) {
            $fields ??= array_keys($row);
            $data[] = $row;
        }

        return [$data, $fields ?? []];
    }

    private function applySheetTitle(Worksheet $sheet, ?string $title): void
    {
        if ($title !== null && $title !== '') {
            $sheet->setTitle(mb_substr(string: $title, start: 0, length: 31));
        }
    }

    /**
     * @param list<string> $fields
     * @param array<string, XlsColumn> $columns
     */
    private function hasTotals(array $fields, array $columns): bool
    {
        foreach ($fields as $index => $field) {
            $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
            if ($columns[$field]->summaryFormula($columnLetter, 2, 2) !== null) {
                return true;
            }
        }

        return false;
    }
}
