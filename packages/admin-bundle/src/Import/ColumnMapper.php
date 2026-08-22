<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import;

use Nubit\ApiPlatform\Attribute\Importable;

/**
 * Proposes which spreadsheet column feeds which field.
 *
 * A proposal, never a decision: the mapping is returned to the user, who
 * confirms or corrects it before anything is written. Guessing silently is how
 * an import writes a phone number into a tax id — the columns were adjacent and
 * both looked like digits.
 *
 * Matching is deliberately conservative. Exact and normalised matches only; a
 * fuzzy match that is right nine times in ten is worse than no match at all,
 * because the tenth is the one nobody checks.
 */
final class ColumnMapper
{
    /**
     * @param list<string> $headers
     *
     * @return array{mapping: array<string, int>, unmapped: list<string>, unused: list<string>}
     */
    public function propose(array $headers, Importable $importable): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $index => $header) {
            $normalizedHeaders[self::normalize($header)][] = $index;
        }

        $mapping = [];
        $usedColumns = [];

        foreach ($importable->fields as $field) {
            $candidates = $normalizedHeaders[self::normalize($field)] ?? [];

            foreach ($candidates as $index) {
                if (!in_array($index, $usedColumns, true)) {
                    $mapping[$field] = $index;
                    $usedColumns[] = $index;
                    break;
                }
            }
        }

        $unmapped = array_values(array_filter(
            $importable->fields,
            static fn(string $field): bool => !isset($mapping[$field]),
        ));

        $unused = [];
        foreach ($headers as $index => $header) {
            if (!in_array($index, $usedColumns, true)) {
                $unused[] = $header;
            }
        }

        return ['mapping' => $mapping, 'unmapped' => $unmapped, 'unused' => $unused];
    }

    /**
     * Validates a mapping the user supplied.
     *
     * @param array<string, int|string> $mapping field => column index
     * @param list<string>              $headers
     *
     * @return array<string, int>
     */
    public function sanitize(array $mapping, array $headers, Importable $importable): array
    {
        $clean = [];

        foreach ($mapping as $field => $column) {
            if (!in_array($field, $importable->fields, true)) {
                throw new Exception\ImportException(sprintf('Field "%s" is not importable on this resource.', $field));
            }

            $index = is_int($column) ? $column : (int) $column;
            if (!array_key_exists($index, $headers)) {
                throw new Exception\ImportException(sprintf('Column %d does not exist in the uploaded file.', $index));
            }

            $clean[$field] = $index;
        }

        foreach ($importable->required as $field) {
            if (!isset($clean[$field])) {
                throw new Exception\ImportException(sprintf('Required field "%s" has no column.', $field));
            }
        }

        foreach ($importable->naturalKey as $field) {
            if (!isset($clean[$field])) {
                throw new Exception\ImportException(sprintf(
                    'Field "%s" is part of the natural key and must be mapped, otherwise existing rows '
                    . 'cannot be matched and the import would duplicate them.',
                    $field,
                ));
            }
        }

        return $clean;
    }

    /** Case, spacing, accents and punctuation are presentation; "Unit Price" is `unitPrice`. */
    private static function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', false === $ascii ? $value : $ascii));
    }
}
