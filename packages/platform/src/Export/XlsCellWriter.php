<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class XlsCellWriter
{
    public function write(Worksheet $sheet, int $column, int $row, mixed $value, XlsColumn $columnSpec): void
    {
        if ($value === null || $value === '') {
            $sheet->setCellValueExplicit([$column, $row], '', DataType::TYPE_STRING);
            return;
        }

        match ($columnSpec->type) {
            XlsColumn::TYPE_NUMBER => $sheet->setCellValueExplicit(
                [$column, $row],
                (float) $value,
                DataType::TYPE_NUMERIC,
            ),
            XlsColumn::TYPE_INTEGER => $sheet->setCellValueExplicit(
                [$column, $row],
                (int) $value,
                DataType::TYPE_NUMERIC,
            ),
            XlsColumn::TYPE_BOOLEAN => $sheet->setCellValueExplicit(
                [$column, $row],
                (bool) $value,
                DataType::TYPE_BOOL,
            ),
            XlsColumn::TYPE_DATE, XlsColumn::TYPE_DATETIME => $this->writeDate($sheet, $column, $row, $value),
            default => $sheet->setCellValueExplicit([$column, $row], (string) $value, DataType::TYPE_STRING),
        };
    }

    private function writeDate(Worksheet $sheet, int $column, int $row, mixed $value): void
    {
        if ($value instanceof \DateTimeInterface) {
            $sheet->setCellValueExplicit([$column, $row], ExcelDate::PHPToExcel($value), DataType::TYPE_NUMERIC);
            return;
        }

        $sheet->setCellValue([$column, $row], $value);
    }
}
