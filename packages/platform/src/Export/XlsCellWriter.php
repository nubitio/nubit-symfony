<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use Nubit\Platform\Money\Money;
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

        // Money arrives from the serializer as {amount, currency, scale}. Left
        // to the generic flattening below it would land in the cell as JSON —
        // technically all the data, and useless: nobody can sum a column of
        // JSON, which is the one thing a reader wants from an exported amount.
        $money = self::asMoney($value);
        if ($money !== null) {
            $this->writeMoney($sheet, $column, $row, $money);
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

    /**
     * Recognises the published money shape, from either the serialized array or
     * a Money object that never went through the serializer.
     *
     * @return array{amount: string, scale: int}|null
     */
    private static function asMoney(mixed $value): ?array
    {
        if ($value instanceof Money) {
            return ['amount' => $value->toDecimalString(), 'scale' => $value->currency->scale];
        }

        if (!is_array($value) || !isset($value['amount'], $value['currency'])) {
            return null;
        }

        $amount = $value['amount'];
        if (!is_string($amount) || preg_match('/^[+-]?\d+(\.\d+)?$/', $amount) !== 1) {
            return null;
        }

        $scale = $value['scale'] ?? null;
        if (!is_int($scale) || $scale < 0) {
            $dot = strpos($amount, '.');
            $scale = false === $dot ? 0 : strlen($amount) - $dot - 1;
        }

        return ['amount' => $amount, 'scale' => $scale];
    }

    /**
     * @param array{amount: string, scale: int} $money
     */
    private function writeMoney(Worksheet $sheet, int $column, int $row, array $money): void
    {
        // The exact decimal literal is handed over rather than a value this
        // code rounded first. A spreadsheet stores numbers as doubles, so the
        // precision ceiling from here on is the file format's, not one the
        // export introduced — and the cell is a number, which is what lets a
        // reader select the column and get a total.
        $sheet->setCellValueExplicit([$column, $row], $money['amount'], DataType::TYPE_NUMERIC);

        // A negative scale would be a corrupt value rather than a currency;
        // clamping renders it as a plain integer instead of failing the export.
        $decimals = max(0, $money['scale']);

        $sheet
            ->getStyle([$column, $row, $column, $row])
            ->getNumberFormat()
            ->setFormatCode(0 === $decimals ? '#,##0' : '#,##0.' . str_repeat('0', $decimals));
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
