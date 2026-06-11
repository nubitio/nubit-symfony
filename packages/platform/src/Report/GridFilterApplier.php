<?php

declare(strict_types=1);

namespace Nubit\Platform\Report;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Translates a DevExtreme grid filter AST (JSON) into a safe SQL WHERE fragment.
 *
 * Each report declares a $fieldMap that maps frontend field names to SQL expressions.
 * Only fields present in the map are included; unknown fields are silently ignored
 * so reports with different joins/aggregates can share the same grid filter payload.
 *
 * Usage:
 *   $sql = $applier->buildSql($rawJsonFilter, $fieldMap, $params);
 *   // $sql is '' or 'AND expr1 AND expr2 ...'
 *   // $params is modified in-place with named placeholders grid_filter_0, grid_filter_1, ...
 */
final class GridFilterApplier
{
    /**
     * Supported comparison operators and their SQL equivalents.
     *
     * @var array<string, string>
     */
    private const OPERATOR_MAP = [
        '='           => '=',
        '<>'          => '<>',
        '>'           => '>',
        '>='          => '>=',
        '<'           => '<',
        '<='          => '<=',
        'contains'    => 'LIKE',
        'notcontains' => 'NOT LIKE',
        'startswith'  => 'LIKE',
        'endswith'    => 'LIKE',
    ];

    /**
     * @param array<string, string>  $fieldMap  Maps frontend field → SQL expression.
     * @param array<string, mixed>   $params    Modified in-place; named params are appended.
     *
     * @throws BadRequestHttpException on malformed JSON filter input.
     */
    public function buildSql(string $rawFilter, array $fieldMap, array &$params): string
    {
        if (trim($rawFilter) === '') {
            return '';
        }

        try {
            $filter = json_decode($rawFilter, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Filtro de reporte inválido.');
        }

        if (!is_array($filter)) {
            return '';
        }

        $conditions = [];
        $index      = count($params);

        foreach ($this->flattenRules($filter) as $rule) {
            if (count($rule) < 3) {
                continue;
            }

            [$field, $operator, $value] = $rule;

            if (!is_string($field) || !is_string($operator)) {
                continue;
            }

            $expression = $fieldMap[$field] ?? null;
            if ($expression === null) {
                continue;
            }

            $sqlOperator = self::OPERATOR_MAP[$operator] ?? null;
            if ($sqlOperator === null) {
                continue;
            }

            $paramName              = sprintf('grid_filter_%d', $index++);
            $conditions[]           = sprintf('%s %s :%s', $expression, $sqlOperator, $paramName);
            $params[$paramName]     = $this->normalizeValue($operator, $value);
        }

        if ($conditions === []) {
            return '';
        }

        return 'AND ' . implode(' AND ', $conditions);
    }

    /**
     * Flatten a DevExtreme filter AST into a list of leaf [field, operator, value] triples.
     *
     * DevExtreme nests conditions as:
     *   [rule1, 'and', rule2, 'and', rule3]
     * where each ruleN is either a leaf triple or another nested array.
     *
     * @param  array<int, mixed>               $filter
     * @return array<int, array<int, mixed>>
     */
    public function flattenRules(array $filter): array
    {
        if (count($filter) >= 3 && is_string($filter[0] ?? null)) {
            return [$filter];
        }

        $rules = [];
        foreach ($filter as $item) {
            if (is_array($item)) {
                array_push($rules, ...$this->flattenRules($item));
            }
        }

        return $rules;
    }

    /**
     * Transform the raw filter value based on operator (e.g., wrap in % for LIKE patterns).
     * Relation identifiers (arrays with an 'id' key) are unwrapped to their scalar id.
     */
    public function normalizeValue(string $operator, mixed $value): string|int|float|bool|null
    {
        $value = $this->unwrapRelation($value);

        return match ($operator) {
            'contains', 'notcontains' => sprintf('%%%s%%', $value),
            'startswith'              => $value . '%',
            'endswith'                => '%' . $value,
            default                   => is_scalar($value) || $value === null ? $value : (string) $value,
        };
    }

    /**
     * If $value is an array with an 'id' key (relation identifier), extract the id.
     */
    private function unwrapRelation(mixed $value): mixed
    {
        if (is_array($value) && isset($value['id'])) {
            return $value['id'];
        }

        return $value;
    }
}
