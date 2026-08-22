<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export\Writer;

use Nubit\Platform\Exception\ServiceException;

/**
 * CSV, written straight to the handle.
 *
 * The floor: no dependency, no buffering, and a file every tool on earth can
 * open. Kept as an option because some integrations consume exports by machine,
 * where a spreadsheet's structure is overhead rather than help.
 */
final class CsvExportWriter implements QueuedExportWriterInterface
{
    /** @var resource|null */
    private mixed $handle = null;

    public function extension(): string
    {
        return 'csv';
    }

    public function mediaType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function open(string $path, array $headers): void
    {
        $handle = fopen($path, 'w');

        if (false === $handle) {
            throw new ServiceException(sprintf('Could not open "%s" for writing.', $path));
        }

        $this->handle = $handle;

        // Excel refuses to read UTF-8 without this, and an export nobody can
        // open is not an export.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($headers), escape: '');
    }

    public function writeRow(array $values): void
    {
        if (null === $this->handle) {
            throw new ServiceException('The writer was not opened.');
        }

        fputcsv($this->handle, $values, escape: '');
    }

    public function close(): void
    {
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
