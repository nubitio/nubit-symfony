<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsColumn
{
    public const string TYPE_STRING = 'string';
    public const string TYPE_NUMBER = 'number';
    public const string TYPE_INTEGER = 'integer';
    public const string TYPE_BOOLEAN = 'boolean';
    public const string TYPE_DATE = 'date';
    public const string TYPE_DATETIME = 'datetime';

    public const string SUMMARY_NONE = 'none';
    public const string SUMMARY_SUM = 'sum';
    public const string SUMMARY_AVERAGE = 'average';
    public const string SUMMARY_COUNT = 'count';
    public const string SUMMARY_MIN = 'min';
    public const string SUMMARY_MAX = 'max';
    public const string SUMMARY_CUSTOM = 'custom';

    public function __construct(
        public string $field,
        public string $label,
        public string $type = self::TYPE_STRING,
        public XlsColumnPresentation $presentation = new XlsColumnPresentation(),
        public XlsColumnSummary $summary = new XlsColumnSummary(),
    ) {}

    public function isNumeric(): bool
    {
        return $this->type === self::TYPE_NUMBER || $this->type === self::TYPE_INTEGER;
    }

    public function summaryFunction(): ?string
    {
        return match ($this->summary->type ?? self::SUMMARY_NONE) {
            self::SUMMARY_SUM => 'SUM',
            self::SUMMARY_AVERAGE => 'AVERAGE',
            self::SUMMARY_COUNT => 'COUNT',
            self::SUMMARY_MIN => 'MIN',
            self::SUMMARY_MAX => 'MAX',
            default => null,
        };
    }

    public function summaryFormula(string $columnLetter, int $startRow, int $endRow): ?string
    {
        if (($this->summary->type ?? self::SUMMARY_NONE) === self::SUMMARY_CUSTOM && $this->summary->formula !== null) {
            return strtr($this->summary->formula, [
                '{column}' => $columnLetter,
                '{startRow}' => (string) $startRow,
                '{endRow}' => (string) $endRow,
                '{range}' => sprintf('%s%d:%s%d', $columnLetter, $startRow, $columnLetter, $endRow),
            ]);
        }

        $function = $this->summaryFunction();
        if ($function === null) {
            return null;
        }

        return sprintf('=%s(%s%d:%s%d)', $function, $columnLetter, $startRow, $columnLetter, $endRow);
    }
}
