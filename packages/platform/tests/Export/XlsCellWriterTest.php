<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Export;

use Nubit\Platform\Export\XlsExporter;
use Nubit\Platform\Money\Money;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * Rows handed to the exporter come straight off the serializer, so a cell may
 * receive an embedded relation rather than a scalar. Those must render as a
 * label: casting them raised "Array to string conversion" and failed the whole
 * export the moment a resource had a relation in its normalization group.
 */
final class XlsCellWriterTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function nestedValueCases(): iterable
    {
        yield 'embedded relation renders its name' => [
            ['id' => 1, 'name' => 'Espresso Machine', 'price' => '450.00'],
            'Espresso Machine',
        ];

        yield 'display field may be a title' => [
            ['id' => 7, 'title' => 'Torre A'],
            'Torre A',
        ];

        yield 'documents fall back to their number' => [
            ['id' => 7, 'number' => 'F001-42'],
            'F001-42',
        ];

        yield 'list of scalars is joined' => [
            ['retail', 'wholesale'],
            'retail, wholesale',
        ];

        yield 'list of relations joins their labels' => [
            [['id' => 1, 'name' => 'Torre A'], ['id' => 2, 'name' => 'Torre B']],
            'Torre A, Torre B',
        ];

        yield 'relation without a display field stays visible as JSON' => [
            ['id' => 3, 'ratio' => 0.5],
            '{"id":3,"ratio":0.5}',
        ];

        yield 'nested empty list renders empty' => [
            [],
            '',
        ];
    }

    #[DataProvider('nestedValueCases')]
    public function testNestedValuesAreFlattenedIntoALabel(mixed $value, string $expected): void
    {
        $sheet = (new XlsExporter())
            ->makeSpreadsheet([['customer' => 'Acme', 'related' => $value]], [
                'customer' => 'Cliente',
                'related' => 'Relacionado',
            ])
            ->getActiveSheet();

        self::assertSame($expected, $sheet->getCell('B2')->getValue());
        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
    }

    public function testStringableObjectUsesItsStringForm(): void
    {
        $value = new class implements Stringable {
            public function __toString(): string
            {
                return 'UNIT-101';
            }
        };

        $sheet = (new XlsExporter())
            ->makeSpreadsheet([['customer' => 'Acme', 'related' => $value]], [
                'customer' => 'Cliente',
                'related' => 'Relacionado',
            ])
            ->getActiveSheet();

        self::assertSame('UNIT-101', $sheet->getCell('B2')->getValue());
    }

    /**
     * A numeric column whose row happens to carry a relation must not be cast
     * to float — that is the same warning by another route.
     */
    public function testNestedValueInANumericColumnDoesNotCrash(): void
    {
        $sheet = (new XlsExporter())
            ->makeSpreadsheet([['amount' => ['id' => 1, 'name' => 'Cuota 1']]], ['amount' => [
                'label' => 'Importe',
                'type' => 'number',
            ]])
            ->getActiveSheet();

        self::assertSame('Cuota 1', $sheet->getCell('A2')->getValue());
    }

    /**
     * An exported amount has to be a number in the spreadsheet. Flattened to
     * JSON it is technically all the data and useless: the reader's first act
     * on an amount column is to select it and read the sum.
     */
    public function testMoneyIsWrittenAsASummableNumber(): void
    {
        $sheet = (new XlsExporter())
            ->makeSpreadsheet([['total' => [
                'amount' => '1234.50',
                'currency' => 'EUR',
                'scale' => 2,
                'minorAmount' => 123450,
            ]]], ['total' => 'Total'])
            ->getActiveSheet();

        // PhpSpreadsheet stores the cell in the spreadsheet's own numeric model
        // once it is typed numeric; the format code is what renders the two
        // decimals back to the reader.
        self::assertSame(1234.5, $sheet->getCell('A2')->getValue());
        self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        self::assertSame('#,##0.00', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
    }

    public function testAMoneyObjectIsWrittenTheSameWay(): void
    {
        $sheet = (new XlsExporter())
            ->makeSpreadsheet([['total' => Money::of('1234.50', 'EUR')]], ['total' => 'Total'])
            ->getActiveSheet();

        self::assertSame(1234.5, $sheet->getCell('A2')->getValue());
        self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
    }

    /** A currency with no minor unit must not gain two decimals in the export. */
    public function testAZeroDecimalCurrencyKeepsItsFormat(): void
    {
        $sheet = (new XlsExporter())
            ->makeSpreadsheet([['total' => ['amount' => '1999', 'currency' => 'JPY', 'scale' => 0]]], [
                'total' => 'Total',
            ])
            ->getActiveSheet();

        self::assertSame(1999, $sheet->getCell('A2')->getValue());
        self::assertSame('#,##0', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
    }

    /** Something that merely has an "amount" key is not money and must not be numeric. */
    public function testANonMoneyArrayIsStillFlattened(): void
    {
        $sheet = (new XlsExporter())
            ->makeSpreadsheet([['total' => ['amount' => 'quite a lot', 'currency' => 'EUR']]], ['total' => 'Total'])
            ->getActiveSheet();

        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
    }

    public function testScalarValuesAreUnaffected(): void
    {
        $sheet = (new XlsExporter())
            ->makeSpreadsheet([['customer' => 'Acme', 'total' => '25.00']], [
                'customer' => 'Cliente',
                'total' => ['label' => 'Total', 'type' => 'number'],
            ])
            ->getActiveSheet();

        self::assertSame('Acme', $sheet->getCell('A2')->getValue());
        self::assertSame(25.0, $sheet->getCell('B2')->getValue());
    }
}
