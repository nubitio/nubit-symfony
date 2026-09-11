<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

class XlsExporter
{
    /**
     * Default ceiling on rows materialized into one inline workbook.
     *
     * PhpSpreadsheet holds the entire workbook in memory before writing a
     * byte, so its cost grows with row count — unlike the queued export path
     * (`openspout/openspout`), which streams rows to disk and stays flat
     * regardless of size (verified at 50,000 rows / ~4 MB peak growth in
     * `XlsxExportTest`). This default is a conservative, easily-overridable
     * bound for the inline path rather than a measured ceiling for it — pick
     * a value your own memory budget supports if you override it.
     */
    public const int DEFAULT_MAX_INLINE_ROWS = 20_000;

    public function __construct(
        private readonly XlsWorksheetWriter $worksheetWriter = new XlsWorksheetWriter(),
        private readonly XlsWriterFactory $writerFactory = new XlsWriterFactory(),
        private readonly XlsResponseFactory $responseFactory = new XlsResponseFactory(),
        private readonly int $maxInlineRows = self::DEFAULT_MAX_INLINE_ROWS,
    ) {}

    public static function withCache(CacheInterface $cache, bool $preCalculateFormulas = false): self
    {
        return new self(writerFactory: new XlsWriterFactory($cache, $preCalculateFormulas));
    }

    /**
     * Stream an XLSX file to the browser.
     *
     * @param array<int, array<string, mixed>> $data
     * @param array<string, string|array<string, mixed>|XlsColumnSpec>|null $headers SQL alias → display label or column options:
     *        label?: string, type?: string, format?: string, summary?: string
     *
     * @throws Exception
     * @throws XlsExportTooLargeException when `$data` exceeds `$maxInlineRows`.
     */
    public function export(array $data, string $filename, ?array $headers = null): StreamedResponse
    {
        return $this->response($this->makeSpreadsheet($data, $headers), $filename);
    }

    /**
     * Save an XLSX file to disk.
     *
     * @param array<int, array<string, mixed>> $data
     * @param array<string, string|array<string, mixed>|XlsColumnSpec>|null $headers
     *
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     * @throws XlsExportTooLargeException when `$data` exceeds `$maxInlineRows`.
     */
    public function save(array $data, string $filename, ?array $headers = null): void
    {
        $spreadsheet = $this->makeSpreadsheet($data, $headers);

        $writer = $this->writerFactory->writer($spreadsheet);
        $writer->save($filename . '.xlsx');
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @param array<string, string|array<string, mixed>|XlsColumnSpec>|null $headers
     *
     * @throws Exception
     * @throws XlsExportTooLargeException when `$data` exceeds `$maxInlineRows`.
     */
    public function makeSpreadsheet(array $data, ?array $headers = null): Spreadsheet
    {
        return $this->makeSpreadsheetFromIterable($data, $headers);
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @param array<string, string|array<string, mixed>|XlsColumnSpec>|null $headers
     * @param list<string>|null $fields
     *
     * @throws Exception
     * @throws XlsExportTooLargeException when `$rows` exceeds `$maxInlineRows`.
     */
    public function makeSpreadsheetFromIterable(
        iterable $rows,
        ?array $headers = null,
        ?array $fields = null,
        XlsSheetOptions $options = new XlsSheetOptions(),
    ): Spreadsheet {
        return $this->makeWorkbook(new XlsWorkbookSpec([
            new XlsSheetSpec(rows: $rows, columns: $headers ?? [], fields: $fields, options: $options),
        ]));
    }

    /**
     * @throws Exception
     * @throws XlsExportTooLargeException when the workbook's row count (summed
     *         across all sheets) exceeds `$maxInlineRows`. The check runs while
     *         rows are being read, not upfront, so a caller streaming rows
     *         from a database never materializes more of them than the limit
     *         allows before the export is refused.
     */
    public function makeWorkbook(XlsWorkbookSpec|XlsWorkbookBuilder $workbook): Spreadsheet
    {
        if ($workbook instanceof XlsWorkbookBuilder) {
            $workbook = $workbook->build();
        }

        $spreadsheet = $this->writerFactory->newSpreadsheet($workbook->creator, $workbook->title);

        if ($workbook->sheets === []) {
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'No data');

            return $spreadsheet;
        }

        $rowsSoFar = 0;

        foreach ($workbook->sheets as $index => $sheetSpec) {
            $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet($index);

            $boundedSpec = new XlsSheetSpec(
                rows: $this->boundRows($sheetSpec->rows, $rowsSoFar),
                columns: $sheetSpec->columns,
                fields: $sheetSpec->fields,
                options: $sheetSpec->options,
            );

            $this->worksheetWriter->write($sheet, $boundedSpec);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Wraps a sheet's row source so the total row count across the whole
     * workbook is enforced as rows are consumed, rather than after the fact.
     *
     * `$rowsSoFar` is shared by reference across every sheet in the workbook,
     * so the limit bounds the workbook as a whole rather than each sheet
     * independently.
     *
     * @param iterable<array<string, mixed>> $rows
     *
     * @return iterable<array<string, mixed>>
     */
    private function boundRows(iterable $rows, int &$rowsSoFar): iterable
    {
        foreach ($rows as $row) {
            if (++$rowsSoFar > $this->maxInlineRows) {
                throw new XlsExportTooLargeException($this->maxInlineRows);
            }

            yield $row;
        }
    }

    /**
     * @throws Exception
     * @throws XlsExportTooLargeException when `$workbook` exceeds `$maxInlineRows`.
     */
    public function exportWorkbook(XlsWorkbookSpec|XlsWorkbookBuilder $workbook, string $filename): StreamedResponse
    {
        return $this->response($this->makeWorkbook($workbook), $filename);
    }

    /**
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     * @throws XlsExportTooLargeException when `$workbook` exceeds `$maxInlineRows`.
     */
    public function saveWorkbook(XlsWorkbookSpec|XlsWorkbookBuilder $workbook, string $filename): void
    {
        $spreadsheet = $this->makeWorkbook($workbook);
        $writer = $this->writerFactory->writer($spreadsheet);
        $writer->save($filename . '.xlsx');
    }

    private function response(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return $this->responseFactory->response($this->writerFactory->writer($spreadsheet), $filename);
    }
}
