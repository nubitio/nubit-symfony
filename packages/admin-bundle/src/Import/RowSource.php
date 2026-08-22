<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import;

use Nubit\AdminBundle\Import\Reader\RowReaderInterface;

/**
 * Binds a reader to a file, so the runner can iterate the rows twice.
 *
 * Twice matters: the dry run and the apply pass read the same file, and a
 * generator is consumed once. Handing the runner a source it can re-open is
 * what keeps "what the report promised" and "what applying did" the same
 * traversal rather than two.
 */
final readonly class RowSource
{
    public function __construct(
        private RowReaderInterface $reader,
        private string $path,
    ) {}

    /** @return list<string> */
    public function headers(): array
    {
        return $this->reader->headers($this->path);
    }

    /** @return \Generator<int, list<string>> */
    public function rows(): \Generator
    {
        yield from $this->reader->rows($this->path);
    }
}
