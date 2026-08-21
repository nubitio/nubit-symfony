<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Export;

use Nubit\AdminBundle\Export\XlsxEncoder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class XlsxEncoderTest extends TestCase
{
    public function testSupportsOnlyTheXlsxFormat(): void
    {
        $encoder = new XlsxEncoder();

        static::assertTrue($encoder->supportsEncoding('xlsx'));
        static::assertFalse($encoder->supportsEncoding('json'));
        static::assertFalse($encoder->supportsEncoding('jsonld'));
    }

    public function testEncodesACollectionListIntoAReadableWorkbook(): void
    {
        $encoder = new XlsxEncoder();

        $bytes = $encoder->encode([
            ['name' => 'Café Roast 250g', 'sku' => 'SKU-001', 'price' => '9.90'],
            ['name' => 'Café Roast 500g', 'sku' => 'SKU-002', 'price' => '17.50'],
        ], 'xlsx');

        $spreadsheet = $this->loadFromBytes($bytes);
        $sheet = $spreadsheet->getActiveSheet();

        // XlsColumnOptionsResolver title-cases field names into headers when
        // no explicit label is configured (ucfirst + underscores-to-spaces) —
        // pre-existing platform behavior, not something this encoder controls.
        static::assertSame('Name', $sheet->getCell('A1')->getValue());
        static::assertSame('Café Roast 250g', $sheet->getCell('A2')->getValue());
        static::assertSame('SKU-002', $sheet->getCell('B3')->getValue());
    }

    public function testEncodesASingleItemAsAOneRowWorkbook(): void
    {
        $encoder = new XlsxEncoder();

        $bytes = $encoder->encode(['name' => 'Café Roast 250g', 'sku' => 'SKU-001'], 'xlsx');

        $sheet = $this->loadFromBytes($bytes)->getActiveSheet();

        static::assertSame('SKU-001', $sheet->getCell('B2')->getValue());
        static::assertNull($sheet->getCell('B3')->getValue());
    }

    public function testEncodesEmptyOrNonArrayDataAsAnEmptyWorkbookInsteadOfFailing(): void
    {
        $encoder = new XlsxEncoder();

        $sheet = $this->loadFromBytes($encoder->encode([], 'xlsx'))->getActiveSheet();
        static::assertSame('No data', $sheet->getCell('A1')->getValue());

        $sheet = $this->loadFromBytes($encoder->encode(null, 'xlsx'))->getActiveSheet();
        static::assertSame('No data', $sheet->getCell('A1')->getValue());
    }

    private function loadFromBytes(string $bytes): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'nubit-xlsx-encoder-test-');
        static::assertIsString($path);
        file_put_contents($path, $bytes);

        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }
}
