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
    private function __construct() {}

    /**
     * Every operator a grid filter leaf may carry.
     *
     * Kept as one list so callers can tell a leaf like `['name', 'contains', 'a']`
     * apart from a list of leaves without guessing from the array shape.
     */
    public const array OPERATORS = [
        'contains',
        'notcontains',
        'startswith',
        'endswith',
        'isnull',
        'isnotnull',
        'in',
        '=',
        '<>',
        '!=',
        '>',
        '>=',
        '<',
        '<=',
    ];

    /** True when `$op` is a grid filter operator. */
    public static function isOperator(mixed $op): bool
    {
        return is_string($op) && in_array($op, self::OPERATORS, true);
    }

    /** True when the operator carries no bound value. */
    public static function isUnaryOperator(mixed $op): bool
    {
        return 'isnull' === $op || 'isnotnull' === $op;
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

    /**
     * Prepares the bound value for the given grid operator (LIKE wildcards, …).
     *
     * Booleans are part of the contract: a grid filtering a checkbox column
     * sends `["paid", "=", true]`, and JSON preserves the type all the way here.
     * Excluding bool from the return type turned that ordinary payload into a
     * TypeError and a 500.
     */
    public static function valueForOperator(string $op, mixed $value): string|int|float|bool|null
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
