<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class XlsExporter
{
    /**
     * Stream an XLSX file to the browser.
     *
     * @param array<int, array<string, mixed>> $data
     * @param array<string, string>|null        $headers  SQL alias → display label
     *
     * @throws Exception
     */
    public function export(array $data, string $filename, ?array $headers = null): StreamedResponse
    {
        $spreadsheet = $this->makeSpreadsheet($data, $headers);

        $writer   = new Xlsx($spreadsheet);
        $response = new StreamedResponse(function () use ($writer): void {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s.xlsx"', $filename));

        return $response;
    }

    /**
     * Save an XLSX file to disk.
     *
     * @param array<int, array<string, mixed>> $data
     * @param array<string, string>|null        $headers
     *
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function save(array $data, string $filename, ?array $headers = null): void
    {
        $spreadsheet = $this->makeSpreadsheet($data, $headers);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filename . '.xlsx');
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @param array<string, string>|null        $headers
     *
     * @throws Exception
     */
    private function makeSpreadsheet(array $data, ?array $headers = null): Spreadsheet
    {
        $headerFillColor = 'FFF2F2F2';
        $headerFontColor = 'FF000000';
        $borderColor     = 'FFBFBFBF';
        $zebraFillColor  = 'FFF9F9F9';

        $headerRow = [];
        if (isset($data[0])) {
            $properties = array_keys($data[0]);
            foreach ($properties as $property) {
                $headerRow[$property] = $headers[$property] ?? ucfirst(str_replace('_', ' ', $property));
            }
            array_unshift($data, $headerRow);
        }

        $columnLetters = range('A', 'Z');
        $headerStyle   = [
            'font'      => [
                'bold'  => true,
                'color' => ['argb' => $headerFontColor],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'fill'    => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => $headerFillColor],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['argb' => $borderColor],
                ],
            ],
        ];

        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['argb' => $borderColor],
                ],
            ],
        ];

        $zebraStyle = new Conditional();
        $zebraStyle->setConditionType(Conditional::CONDITION_EXPRESSION);
        $zebraStyle->setOperatorType(Conditional::OPERATOR_EQUAL);
        $zebraStyle->addCondition('MOD(ROW(),2)=0');
        $zebraStyle->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
        $zebraStyle->getStyle()->getFill()->getStartColor()->setARGB($zebraFillColor);
        $zebraBorders = $zebraStyle->getStyle()->getBorders();
        $zebraBorders->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB($borderColor);
        $zebraBorders->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB($borderColor);
        $zebraBorders->getLeft()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB($borderColor);
        $zebraBorders->getRight()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB($borderColor);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Nubit')
            ->setTitle('Nubit export');

        $spreadsheet->setActiveSheetIndex(0);
        if ($data !== []) {
            $spreadsheet->getActiveSheet()->fromArray($data);
        } else {
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'No data');
        }

        $lastColumn = [] === $headerRow ? 'A' : $columnLetters[count($headerRow) - 1];
        foreach (range('A', $lastColumn) as $columnID) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . $lastColumn . '1');
        $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray($headerStyle);

        if (count($data) > 1) {
            $range = 'A2:' . $lastColumn . count($data);
            $sheet->getStyle($range)->setConditionalStyles([$zebraStyle]);
            $sheet->getStyle($range)->applyFromArray($borderStyle);
        }

        return $spreadsheet;
    }
}
