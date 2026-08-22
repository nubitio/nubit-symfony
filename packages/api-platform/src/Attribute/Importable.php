<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

/**
 * Declares that a resource can be loaded from a spreadsheet.
 *
 * Every ERP migration starts with somebody's existing data in a file, so this
 * is not a convenience feature — it is the first thing a new customer needs and
 * the last thing anyone wants to write by hand for the fourth time.
 *
 * ```php
 * #[ApiResource]
 * #[Importable(fields: ['sku', 'name', 'price'], naturalKey: ['sku'])]
 * class Product { … }
 * ```
 *
 * The natural key is what makes an import re-runnable. Without one, re-uploading
 * a corrected file duplicates every row that was already fine; with one, rows
 * are matched and updated instead.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Importable
{
    /**
     * @param list<string> $fields     Properties that may be written. An explicit list rather
     *                                 than "everything writable": an import file is untrusted
     *                                 input, and a column nobody meant to expose is a way to
     *                                 set it.
     * @param list<string> $naturalKey Properties identifying an existing row. Empty means
     *                                 insert-only, and a re-run duplicates.
     * @param list<string> $required   Properties a row must carry a value for.
     * @param int          $batchSize  Rows per flush while applying.
     * @param int          $maxRows    Upper bound on file size, in rows.
     */
    public function __construct(
        public array $fields,
        public array $naturalKey = [],
        public array $required = [],
        public int $batchSize = 200,
        public int $maxRows = 100_000,
    ) {
        if ([] === $fields) {
            throw new \InvalidArgumentException('An importable resource must list at least one field.');
        }

        foreach ($naturalKey as $key) {
            if (!in_array($key, $fields, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Natural key "%s" is not among the importable fields.',
                    $key,
                ));
            }
        }

        foreach ($required as $field) {
            if (!in_array($field, $fields, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Required field "%s" is not among the importable fields.',
                    $field,
                ));
            }
        }
    }
}
