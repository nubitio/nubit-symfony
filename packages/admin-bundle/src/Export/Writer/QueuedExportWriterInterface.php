<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export\Writer;

/**
 * Writes a queued export, one row at a time.
 *
 * A port rather than a class, because the constraint that matters is not the
 * file format — it is that rows reach the disk as they are read. Anything that
 * buffers the whole sheet first puts back the limit queueing exists to remove,
 * whatever extension it writes.
 */
interface QueuedExportWriterInterface
{
    /** File extension, without the dot. */
    public function extension(): string;

    public function mediaType(): string;

    /**
     * Opens the file and writes the header row.
     *
     * @param array<string, string> $headers property => column title
     */
    public function open(string $path, array $headers): void;

    /** @param list<string> $values one per header, in the same order */
    public function writeRow(array $values): void;

    public function close(): void;
}
