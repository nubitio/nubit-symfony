<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class XlsDataValidationApplier
{
    public function apply(Worksheet $sheet, string $column, XlsSheetLayout $layout, XlsColumn $columnSpec): void
    {
        if ($columnSpec->presentation->validation === null || $layout->rowCount === 0) {
            return;
        }

        $spec = $columnSpec->presentation->validation;
        $validation = new DataValidation();
        $validation->setType($spec->type);
        $validation->setAllowBlank($spec->allowBlank);

        $this->applyOperator($validation, $spec);
        $this->applyFormula($validation, $spec);
        $this->applyPrompt($validation, $spec);
        $this->applyError($validation, $spec);

        $sheet->setDataValidation($column . '2:' . $column . $layout->lastDataRow, $validation);
    }

    private function applyOperator(DataValidation $validation, XlsValidationSpec $spec): void
    {
        if ($spec->rule->operator !== null) {
            $validation->setOperator($spec->rule->operator);
        }
    }

    private function applyFormula(DataValidation $validation, XlsValidationSpec $spec): void
    {
        if ($spec->rule->values !== null) {
            $validation->setFormula1('"' . implode(',', $spec->rule->values) . '"');
            return;
        }

        if ($spec->rule->formula1 !== null) {
            $validation->setFormula1($spec->rule->formula1);
        }

        if ($spec->rule->formula2 !== null) {
            $validation->setFormula2($spec->rule->formula2);
        }
    }

    private function applyPrompt(DataValidation $validation, XlsValidationSpec $spec): void
    {
        if ($spec->messages->promptTitle === null && $spec->messages->prompt === null) {
            return;
        }

        $validation->setShowInputMessage(true);
        $validation->setPromptTitle($spec->messages->promptTitle ?? '');
        $validation->setPrompt($spec->messages->prompt ?? '');
    }

    private function applyError(DataValidation $validation, XlsValidationSpec $spec): void
    {
        if ($spec->messages->errorTitle === null && $spec->messages->error === null) {
            return;
        }

        $validation->setShowErrorMessage(true);
        $validation->setErrorTitle($spec->messages->errorTitle ?? '');
        $validation->setError($spec->messages->error ?? '');
    }
}
