<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export\Writer;

use Nubit\Platform\Exception\ServiceException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * XLSX, written a row at a time.
 *
 * OpenSpout rather than PhpSpreadsheet, and that is the whole point:
 * PhpSpreadsheet builds the entire workbook in memory before writing a byte, so
 * a half-million-row sheet is an out-of-memory error at the ninety percent
 * mark. OpenSpout appends each row to the sheet XML as it arrives and zips at
 * the end, so memory is flat regardless of size.
 *
 * The cost is features. There are no formulas, no data validation and no totals
 * row here — those live on the inline export, which is a presentation artifact
 * with a bounded row count. This one is a data dump, and a data dump of half a
 * million rows that opens is worth more than a beautiful one that never
 * finishes.
 */
final class XlsxExportWriter implements QueuedExportWriterInterface
{
    private ?Writer $writer = null;

    public function extension(): string
    {
        return 'xlsx';
    }

    public function mediaType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function open(string $path, array $headers): void
    {
        $options = new Options();
        // Inline strings, not the shared-strings table: the table is the one
        // structure in the format that grows with the data, and using it would
        // reintroduce the memory ceiling by the back door.
        $options->SHOULD_USE_INLINE_STRINGS = true;

        $writer = new Writer($options);
        $writer->openToFile($path);

        $bold = new Style();
        $bold->setFontBold();
        $writer->addRow(Row::fromValues(array_values($headers), $bold));

        $this->writer = $writer;
    }

    public function writeRow(array $values): void
    {
        if (null === $this->writer) {
            throw new ServiceException('The writer was not opened.');
        }

        // Every value is already rendered to a string by ExportRowMapper; the
        // cast is here so the writer's own signature is satisfied rather than
        // trusted.
        $this->writer->addRow(Row::fromValues(array_map(strval(...), $values)));
    }

    public function close(): void
    {
        $this->writer?->close();
        $this->writer = null;
    }
}
