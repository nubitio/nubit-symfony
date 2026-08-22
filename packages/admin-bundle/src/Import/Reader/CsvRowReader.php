<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import\Reader;

use Nubit\Platform\Exception\ServiceException;

/**
 * Reads CSV, including the dialects spreadsheets actually produce.
 *
 * The delimiter is detected rather than assumed: Excel writes semicolons in
 * every locale that uses a comma as the decimal separator, which is most of the
 * ones an ERP is sold into. A hard-coded comma turns those files into a single
 * column and reports every row as invalid.
 */
final class CsvRowReader implements RowReaderInterface
{
    private const array DELIMITERS = [',', ';', "\t", '|'];

    public function supports(string $filename, string $mediaType): bool
    {
        return (
            str_ends_with(strtolower($filename), '.csv')
            || in_array($mediaType, ['text/csv', 'text/plain', 'application/csv'], true)
        );
    }

    public function headers(string $path): array
    {
        $delimiter = $this->detectDelimiter($path);
        $handle = $this->open($path);

        $row = fgetcsv($handle, separator: $delimiter, escape: '');
        fclose($handle);

        if (false === $row || null === $row) {
            throw new ServiceException('The file has no header row.');
        }

        return $this->normalize($row);
    }

    public function rows(string $path): \Generator
    {
        $handle = $this->open($path);
        $delimiter = $this->detectDelimiter($path);

        $lineNumber = 0;

        try {
            while (false !== ($row = fgetcsv($handle, separator: $delimiter, escape: ''))) {
                ++$lineNumber;

                if (1 === $lineNumber) {
                    continue;
                }

                if (null === $row || [null] === $row) {
                    continue;
                }

                $values = $this->normalize($row);

                // A trailing newline produces a row of one empty string. Files
                // end that way far more often than not.
                if (1 === count($values) && '' === $values[0]) {
                    continue;
                }

                yield $lineNumber => $values;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function open(string $path)
    {
        if (!is_readable($path)) {
            throw new ServiceException(sprintf('Could not read the import file at "%s".', $path));
        }

        $handle = fopen($path, 'r');
        if (false === $handle) {
            throw new ServiceException(sprintf('Could not open the import file at "%s".', $path));
        }

        return $handle;
    }

    /**
     * Picks the delimiter that splits the header into the most columns.
     *
     * Counting occurrences would pick the comma for "Smith, John";Acme — the
     * quoted field inflates its count. Splitting properly and comparing the
     * resulting column count does not.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = $this->open($path);
        $header = fgets($handle);
        fclose($handle);

        if (false === $header) {
            return ',';
        }

        $best = ',';
        $bestColumns = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $columns = count(str_getcsv(rtrim($header, "\r\n"), $delimiter, escape: ''));
            if ($columns > $bestColumns) {
                $best = $delimiter;
                $bestColumns = $columns;
            }
        }

        return $best;
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return list<string>
     */
    private function normalize(array $row): array
    {
        $values = array_map(static fn(mixed $value): string => trim(
            is_scalar($value) ? (string) $value : '',
        ), array_values($row));

        // Excel writes a UTF-8 BOM ahead of the first cell, which otherwise
        // becomes part of the first header's name and matches nothing.
        if ([] !== $values) {
            $values[0] = preg_replace('/^\x{FEFF}/u', '', $values[0]) ?? $values[0];
        }

        return $values;
    }
}
