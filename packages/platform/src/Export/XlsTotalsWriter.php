<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class XlsTotalsWriter
{
    /**
     * @param array<string, XlsColumn> $columns
     */
    public function write(Worksheet $sheet, XlsSheetLayout $layout, array $columns): void
    {
        $sheet->setCellValue(
            [$this->firstTextColumn($layout->fields, $columns), $layout->totalsRow],
            $this->totalsLabel($columns),
        );

        if ($layout->rowCount === 0) {
            return;
        }

        foreach ($layout->fields as $index => $field) {
            $this->writeFormula($sheet, $index + 1, $layout, $columns[$field]);
        }
    }

    private function writeFormula(Worksheet $sheet, int $columnIndex, XlsSheetLayout $layout, XlsColumn $column): void
    {
        $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);
        $formula = $column->summaryFormula($columnLetter, 2, $layout->lastDataRow);
        if ($formula !== null) {
            $sheet->setCellValue([$columnIndex, $layout->totalsRow], $formula);
        }
    }

    /**
     * @param list<string> $fields
     * @param array<string, XlsColumn> $columns
     */
    private function firstTextColumn(array $fields, array $columns): int
    {
        foreach ($fields as $index => $field) {
            if (!$columns[$field]->isNumeric()) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * @param array<string, XlsColumn> $columns
     */
    private function totalsLabel(array $columns): string
    {
        foreach ($columns as $column) {
            if ($column->summary->label !== null) {
                return $column->summary->label;
            }
        }

        return 'TOTALES';
    }
}
