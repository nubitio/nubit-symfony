<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

class XlsExporter
{
    public function __construct(
        private readonly XlsWorksheetWriter $worksheetWriter = new XlsWorksheetWriter(),
        private readonly XlsWriterFactory $writerFactory = new XlsWriterFactory(),
        private readonly XlsResponseFactory $responseFactory = new XlsResponseFactory(),
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

        foreach ($workbook->sheets as $index => $sheetSpec) {
            $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet($index);

            $this->worksheetWriter->write($sheet, $sheetSpec);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @throws Exception
     */
    public function exportWorkbook(XlsWorkbookSpec|XlsWorkbookBuilder $workbook, string $filename): StreamedResponse
    {
        return $this->response($this->makeWorkbook($workbook), $filename);
    }

    /**
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
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
