<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import\Reader;

/**
 * Reads a tabular file one row at a time.
 *
 * A generator rather than an array: import files are the largest thing an
 * application ingests, and holding a hundred thousand rows in memory to count
 * them is how an import becomes an out-of-memory error on the customer's
 * biggest file — the one that mattered.
 */
interface RowReaderInterface
{
    public function supports(string $filename, string $mediaType): bool;

    /** @return list<string> The header row, trimmed. */
    public function headers(string $path): array;

    /**
     * Data rows, excluding the header.
     *
     * @return \Generator<int, list<string>> keyed by the file's own 1-based row number, so an
     *                                       error message points at the line the user can see
     */
    public function rows(string $path): \Generator;
}
