<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use DateTimeInterface;

final class XlsColumnTypeResolver
{
    /**
     * @param list<mixed> $values
     */
    public function infer(array $values): string
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            return match (true) {
                $value instanceof DateTimeInterface => XlsColumn::TYPE_DATETIME,
                is_int($value) => XlsColumn::TYPE_INTEGER,
                is_float($value) => XlsColumn::TYPE_NUMBER,
                is_bool($value) => XlsColumn::TYPE_BOOLEAN,
                default => XlsColumn::TYPE_STRING,
            };
        }

        return XlsColumn::TYPE_STRING;
    }

    public function defaultFormat(string $type): ?string
    {
        return match ($type) {
            XlsColumn::TYPE_NUMBER => '#,##0.###',
            XlsColumn::TYPE_INTEGER => '#,##0',
            XlsColumn::TYPE_DATE => 'yyyy-mm-dd',
            XlsColumn::TYPE_DATETIME => 'yyyy-mm-dd hh:mm',
            default => null,
        };
    }
}
