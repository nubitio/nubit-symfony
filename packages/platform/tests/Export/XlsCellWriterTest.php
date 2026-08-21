<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Export;

use Nubit\Platform\Export\XlsExporter;
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
