<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine\Filter;

use Doctrine\ORM\QueryBuilder;

/**
 * Shared helpers for translating grid filter operators into DQL.
 * Used by DataGridFilter and by GridVirtualFieldInterface implementations.
 */
final class GridFilterHelper
{
    private function __construct()
    {
    }

    /** Maps a grid operator to its DQL operator. */
    public static function dqlOperator(string $op): string
    {
        return match ($op) {
            'contains', 'startswith', 'endswith' => 'LIKE',
            'notcontains' => 'NOT LIKE',
            'isnull' => 'IS NULL',
            'isnotnull' => 'IS NOT NULL',
            default => $op,
        };
    }

    /** Prepares the bound value for the given grid operator (LIKE wildcards, …). */
    public static function valueForOperator(string $op, mixed $value): string|int|float|null
    {
        return match ($op) {
            'contains', 'notcontains' => sprintf('%%%s%%', $value),
            'startswith' => $value . '%',
            'endswith' => '%' . $value,
            'isnull', 'isnotnull' => null,
            default => $value,
        };
    }

    /** Returns a parameter name not yet bound on the QueryBuilder. */
    public static function uniqueParameterName(QueryBuilder $queryBuilder, string $field): string
    {
        $index = 1;
        $parameterName = $field;

        while ($queryBuilder->getParameter($parameterName)) {
            $parameterName = $field . $index++;
        }

        return $parameterName;
    }
}
