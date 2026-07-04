<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class XlsSpreadsheetStyler
{
    private const string HEADER_FILL_COLOR = 'FFF2F2F2';
    private const string HEADER_FONT_COLOR = 'FF000000';
    private const string BORDER_COLOR = 'FFBFBFBF';
    private const string ZEBRA_FILL_COLOR = 'FFF9F9F9';
    private const string TOTALS_FILL_COLOR = 'FFEFF6FF';

    public function __construct(
        private readonly XlsDataValidationApplier $validationApplier = new XlsDataValidationApplier(),
        private readonly XlsTableApplier $tableApplier = new XlsTableApplier(),
    ) {}

    /**
     * @param list<string> $fields
     * @param array<string, XlsColumn> $columns
     */
    public function apply(Worksheet $sheet, XlsSheetLayout $layout, array $columns, XlsSheetOptions $options = new XlsSheetOptions()): void
    {
        $this->autoSizeColumns($sheet, count($layout->fields));

        if ($options->freezeHeader) {
            $sheet->freezePane('A2');
        }

        if ($options->autoFilter) {
            $sheet->setAutoFilter('A1:' . $layout->lastColumn . '1');
        }

        $sheet->getStyle('A1:' . $layout->lastColumn . '1')->applyFromArray($this->headerStyle());

        if ($layout->rowCount > 0) {
            $dataRange = 'A2:' . $layout->lastColumn . $layout->lastDataRow;
            $sheet->getStyle($dataRange)->setConditionalStyles([$this->zebraStyle()]);
            $sheet->getStyle($dataRange)->applyFromArray($this->borderStyle());
        }

        if ($options->showTotals) {
            $sheet->getStyle('A' . $layout->totalsRow . ':' . $layout->lastColumn . $layout->totalsRow)->applyFromArray($this->totalsStyle());
        }

        foreach ($layout->fields as $index => $field) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $columnSpec = $columns[$field];

            if ($columnSpec->presentation->width !== null) {
                $this->applyWidth($sheet, $column, $columnSpec->presentation->width);
            }

            if ($columnSpec->presentation->format === null) {
                $this->applyAlignment($sheet, $column, $layout, $columnSpec);
                $this->validationApplier->apply($sheet, $column, $layout, $columnSpec);
                continue;
            }

            $sheet->getStyle($column . '2:' . $column . $layout->totalsRow)
                ->getNumberFormat()
                ->setFormatCode($columnSpec->presentation->format);

            $this->applyAlignment($sheet, $column, $layout, $columnSpec);
            $this->validationApplier->apply($sheet, $column, $layout, $columnSpec);
        }

        $this->tableApplier->apply($sheet, $layout, $options);
    }

    private function autoSizeColumns(Worksheet $sheet, int $columnCount): void
    {
        for ($columnIndex = 1; $columnIndex <= $columnCount; ++$columnIndex) {
            $columnID = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }
    }

    private function applyWidth(Worksheet $sheet, string $column, float|string $width): void
    {
        if ($width === 'auto') {
            $sheet->getColumnDimension($column)->setAutoSize(true);
            return;
        }

        $sheet->getColumnDimension($column)->setAutoSize(false);
        $sheet->getColumnDimension($column)->setWidth((float) $width);
    }

    private function applyAlignment(Worksheet $sheet, string $column, XlsSheetLayout $layout, XlsColumn $columnSpec): void
    {
        $alignment = $columnSpec->presentation->alignment ?? ($columnSpec->isNumeric() ? Alignment::HORIZONTAL_RIGHT : null);
        if ($alignment === null) {
            return;
        }

        $sheet->getStyle($column . '2:' . $column . $layout->totalsRow)
            ->getAlignment()
            ->setHorizontal($alignment);
    }

    /**
     * @return array<string, mixed>
     */
    private function headerStyle(): array
    {
        return [
            'font' => [
                'bold' => true,
                'color' => ['argb' => self::HEADER_FONT_COLOR],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => self::HEADER_FILL_COLOR],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => self::BORDER_COLOR],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function borderStyle(): array
    {
        return [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => self::BORDER_COLOR],
                ],
            ],
        ];
    }

    private function zebraStyle(): Conditional
    {
        $zebraStyle = new Conditional();
        $zebraStyle->setConditionType(Conditional::CONDITION_EXPRESSION);
        $zebraStyle->setOperatorType(Conditional::OPERATOR_EQUAL);
        $zebraStyle->addCondition('MOD(ROW(),2)=0');
        $zebraStyle->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
        $zebraStyle->getStyle()->getFill()->getStartColor()->setARGB(self::ZEBRA_FILL_COLOR);

        $zebraBorders = $zebraStyle->getStyle()->getBorders();
        $zebraBorders->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::BORDER_COLOR);
        $zebraBorders->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::BORDER_COLOR);
        $zebraBorders->getLeft()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::BORDER_COLOR);
        $zebraBorders->getRight()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::BORDER_COLOR);

        return $zebraStyle;
    }

    /**
     * @return array<string, mixed>
     */
    private function totalsStyle(): array
    {
        return [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => self::TOTALS_FILL_COLOR],
            ],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::BORDER_COLOR]],
            ],
        ];
    }
}
