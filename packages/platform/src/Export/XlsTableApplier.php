<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class XlsTableApplier
{
    public function apply(Worksheet $sheet, XlsSheetLayout $layout, XlsSheetOptions $options): void
    {
        if ($options->table === null || $layout->rowCount === 0) {
            return;
        }

        $table = new Table('A1:' . $layout->lastColumn . $layout->lastDataRow, $this->validTableName($options->table->name));
        $table->setAllowFilter($options->autoFilter);

        $style = new TableStyle();
        $style->setTheme($options->table->style);
        $style->setShowRowStripes(true);
        $table->setStyle($style);

        $sheet->addTable($table);
    }

    private function validTableName(string $name): string
    {
        $name = preg_replace(pattern: '/[^A-Za-z0-9_]/', replacement: '_', subject: $name) ?? 'Table1';
        $name = ltrim(string: $name, characters: '0123456789');

        return $name === '' ? 'Table1' : $name;
    }
}
