<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Export;

use Nubit\Platform\Export\XlsColumn;
use Nubit\Platform\Export\XlsColumnSpec;
use Nubit\Platform\Export\XlsExporter;
use Nubit\Platform\Export\XlsSheetOptions;
use Nubit\Platform\Export\XlsTableOptions;
use Nubit\Platform\Export\XlsValidationSpec;
use Nubit\Platform\Export\XlsWorkbookBuilder;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class XlsExporterTest extends TestCase
{
    public function testWritesNumericReportColumnsAsNumbersAndAddsTotals(): void
    {
        $spreadsheet = (new XlsExporter())->makeSpreadsheet([
            [
                'document' => 'F001-1',
                'customer_name' => 'Cliente A',
                'quantity' => '2.500',
                'unit_price' => '10.00',
                'total' => '25.00',
            ],
            [
                'document' => 'F001-2',
                'customer_name' => 'Cliente B',
                'quantity' => '1.000',
                'unit_price' => '20.00',
                'total' => '20.00',
            ],
        ], [
            'document' => 'Comprobante',
            'customer_name' => 'Cliente',
            'quantity' => ['label' => 'Cantidad', 'type' => 'number'],
            'unit_price' => ['label' => 'Precio unit.', 'type' => 'number', 'format' => '#,##0.00', 'summary' => 'none'],
            'total' => ['label' => 'Total', 'type' => 'number', 'format' => '#,##0.00'],
        ]);

        $sheet = $spreadsheet->getActiveSheet();

        static::assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        static::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C2')->getDataType());
        static::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
        static::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('E2')->getDataType());
        static::assertSame(2.5, $sheet->getCell('C2')->getValue());
        static::assertSame(25.0, $sheet->getCell('E2')->getValue());

        static::assertSame('TOTALES', $sheet->getCell('A4')->getValue());
        static::assertSame('=SUM(C2:C3)', $sheet->getCell('C4')->getValue());
        static::assertNull($sheet->getCell('D4')->getValue());
        static::assertSame('=SUM(E2:E3)', $sheet->getCell('E4')->getValue());
    }

    public function testKeepsIdentityLikeNumbersAsText(): void
    {
        $spreadsheet = (new XlsExporter())->makeSpreadsheet([
            [
                'customer_ruc' => '20123456789',
                'number' => '00000123',
                'total' => '118.00',
            ],
        ], [
            'customer_ruc' => 'RUC/DNI',
            'number' => 'Número',
            'total' => ['label' => 'Total', 'type' => 'number'],
        ]);

        $sheet = $spreadsheet->getActiveSheet();

        static::assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        static::assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
        static::assertSame('20123456789', $sheet->getCell('A2')->getValue());
        static::assertSame('00000123', $sheet->getCell('B2')->getValue());
        static::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C2')->getDataType());
    }

    public function testNumericStringsStayAsTextWithoutExplicitColumnType(): void
    {
        $spreadsheet = (new XlsExporter())->makeSpreadsheet([
            [
                'external_id' => '00000123',
                'amount' => '118.00',
            ],
        ], [
            'external_id' => 'External ID',
            'amount' => 'Amount',
        ]);

        $sheet = $spreadsheet->getActiveSheet();

        static::assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        static::assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
        static::assertSame('118.00', $sheet->getCell('B2')->getValue());
        static::assertNull($sheet->getCell('A3')->getValue());
        static::assertNull($sheet->getCell('B3')->getValue());
    }

    public function testSupportsMoreThanTwentySixColumns(): void
    {
        $row = [];
        $headers = [];
        for ($i = 1; $i <= 28; ++$i) {
            $field = 'field_' . $i;
            $row[$field] = 'v' . $i;
            $headers[$field] = 'Field ' . $i;
        }

        $spreadsheet = (new XlsExporter())->makeSpreadsheet([$row], $headers);

        static::assertSame('Field 28', $spreadsheet->getActiveSheet()->getCell('AB1')->getValue());
        static::assertSame('v28', $spreadsheet->getActiveSheet()->getCell('AB2')->getValue());
    }

    public function testWritesDateTimeColumnsAsExcelNumericDates(): void
    {
        $date = new \DateTimeImmutable('2026-07-04 13:45:00');

        $spreadsheet = (new XlsExporter())->makeSpreadsheet([
            [
                'created_at' => $date,
            ],
        ], [
            'created_at' => ['label' => 'Created at', 'type' => 'datetime'],
        ]);

        $cell = $spreadsheet->getActiveSheet()->getCell('A2');

        static::assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
        static::assertSame(ExcelDate::PHPToExcel($date), $cell->getValue());
    }

    public function testSupportsTypedColumnSpecSummariesLayoutValidationTableAndBuilder(): void
    {
        $builder = XlsWorkbookBuilder::create('Operations')
            ->sheet(
                title: 'Sales',
                rows: [
                    ['status' => 'paid', 'quantity' => 2, 'amount' => 20.5],
                    ['status' => 'void', 'quantity' => 4, 'amount' => 30.0],
                ],
                columns: [
                    'status' => XlsColumnSpec::text('Status')
                        ->withWidth(16)
                        ->withValidation(XlsValidationSpec::list(['paid', 'void'])),
                    'quantity' => XlsColumnSpec::integer('Qty')
                        ->withSummary(XlsColumn::SUMMARY_MAX),
                    'amount' => XlsColumnSpec::number('Amount', '#,##0.00')
                        ->withSummary(XlsColumn::SUMMARY_CUSTOM, '=SUM({range})/2', 'TOTAL'),
                ],
                options: new XlsSheetOptions(
                    title: 'Sales',
                    freezeHeader: false,
                    autoFilter: true,
                    showTotals: true,
                    table: new XlsTableOptions('SalesTable'),
                ),
            )
            ->sheet(
                title: 'Summary',
                rows: [
                    ['metric' => 'orders', 'value' => 2],
                ],
                columns: [
                    'metric' => 'Metric',
                    'value' => XlsColumnSpec::integer('Value'),
                ],
            );

        $spreadsheet = (new XlsExporter())->makeWorkbook($builder);
        $sales = $spreadsheet->getSheet(0);
        $summary = $spreadsheet->getSheet(1);

        static::assertSame('Operations', $spreadsheet->getProperties()->getTitle());
        static::assertSame('Sales', $sales->getTitle());
        static::assertSame('Summary', $summary->getTitle());
        static::assertSame(['SalesTable'], $sales->getTableNames());
        static::assertNull($sales->getFreezePane());
        static::assertSame(16.0, $sales->getColumnDimension('A')->getWidth());
        static::assertSame('TOTAL', $sales->getCell('A4')->getValue());
        static::assertSame('=MAX(B2:B3)', $sales->getCell('B4')->getValue());
        static::assertSame('=SUM(C2:C3)/2', $sales->getCell('C4')->getValue());
        static::assertSame(Alignment::HORIZONTAL_RIGHT, $sales->getStyle('C2')->getAlignment()->getHorizontal());
        static::assertSame('"paid,void"', $sales->getDataValidation('A2:A3')->getFormula1());
        static::assertSame('orders', $summary->getCell('A2')->getValue());
    }

    public function testSupportsIterableRowsWithConfiguredFields(): void
    {
        $rows = (static function (): \Generator {
            yield ['name' => 'A', 'amount' => '10.00'];
            yield ['name' => 'B', 'amount' => '20.00'];
        })();

        $spreadsheet = (new XlsExporter())->makeSpreadsheetFromIterable(
            rows: $rows,
            headers: [
                'name' => 'Name',
                'amount' => XlsColumnSpec::number('Amount', '#,##0.00'),
            ],
            fields: ['name', 'amount'],
        );

        $sheet = $spreadsheet->getActiveSheet();

        static::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        static::assertSame('=SUM(B2:B3)', $sheet->getCell('B4')->getValue());
    }

    public function testCanInstallPsrCacheBeforeCreatingWorkbook(): void
    {
        $cache = new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $values = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->values[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->values[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->values = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                foreach ($keys as $key) {
                    yield $key => $this->get($key, $default);
                }
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set((string) $key, $value, $ttl);
                }

                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->values);
            }
        };

        XlsExporter::withCache($cache)->makeSpreadsheet([
            ['name' => 'A'],
        ]);

        static::assertSame($cache, Settings::getCache());
    }
}
