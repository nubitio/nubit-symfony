<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import\Reader;

use Nubit\Platform\Exception\ServiceException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads the first worksheet of an XLSX file.
 *
 * Read-only mode is not an optimisation here, it is the difference between
 * working and not: PhpSpreadsheet's full model holds styles and formulas for
 * every cell, and a customer's real product export is exactly the file that
 * exhausts memory.
 *
 * Dates are converted back to text rather than handed over as Excel serial
 * numbers. A date column read raw arrives as 45000, and every row fails
 * validation for a reason nobody reading the report could guess.
 */
final class XlsxRowReader implements RowReaderInterface
{
    public function supports(string $filename, string $mediaType): bool
    {
        $lower = strtolower($filename);

        return (
            str_ends_with($lower, '.xlsx')
            || str_ends_with($lower, '.xls')
            || str_contains($mediaType, 'spreadsheetml')
            || 'application/vnd.ms-excel' === $mediaType
        );
    }

    public function headers(string $path): array
    {
        foreach ($this->readRows($path, limit: 1) as $row) {
            return $row;
        }

        throw new ServiceException('The spreadsheet has no header row.');
    }

    public function rows(string $path): \Generator
    {
        $number = 0;

        foreach ($this->readRows($path) as $row) {
            ++$number;

            if (1 === $number) {
                continue;
            }

            if ([] === array_filter($row, static fn(string $value): bool => '' !== $value)) {
                continue;
            }

            yield $number => $row;
        }
    }

    /** @return \Generator<int, list<string>> */
    private function readRows(string $path, ?int $limit = null): \Generator
    {
        $reader = $this->reader($path);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $read = 0;
        foreach ($sheet->getRowIterator() as $row) {
            $values = [];
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(false);

            foreach ($cells as $cell) {
                // A gap in a sparse row comes through as null; the column still
                // exists and has to keep its position in the row.
                $values[] = null === $cell ? '' : $this->cellText($cell);
            }

            yield $values;

            if (null !== $limit && ++$read >= $limit) {
                break;
            }
        }

        $spreadsheet->disconnectWorksheets();
    }

    private function cellText(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        /** @var mixed $value */
        $value = $cell->getValue();

        if (null === $value) {
            return '';
        }

        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    private function reader(string $path): IReader
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
        } catch (\Throwable $exception) {
            throw new ServiceException('The file is not a spreadsheet this importer can read.', previous: $exception);
        }

        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        return $reader;
    }
}
