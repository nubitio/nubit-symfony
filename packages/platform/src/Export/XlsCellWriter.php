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

        // A row straight off the serializer carries embedded relations as
        // nested arrays. A spreadsheet cell holds one value, so those are
        // flattened to a label here — casting them would emit an "Array to
        // string conversion" warning and fail the whole export.
        if (is_array($value) || is_object($value) && !$value instanceof \DateTimeInterface) {
            $sheet->setCellValueExplicit([$column, $row], self::flatten($value), DataType::TYPE_STRING);
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

    /**
     * Renders a nested value as the label a reader expects in a cell.
     *
     * Embedded relations are shown by their display field, lists are joined,
     * and anything without an obvious label falls back to compact JSON so the
     * data is still visible rather than silently dropped.
     */
    private static function flatten(mixed $value): string
    {
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return (string) $value;
        }

        if (array_is_list($value)) {
            return implode(', ', array_map(self::flatten(...), $value));
        }

        foreach (['name', 'title', 'label', 'reference', 'number', 'code'] as $displayField) {
            if (isset($value[$displayField]) && is_scalar($value[$displayField])) {
                return (string) $value[$displayField];
            }
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
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
