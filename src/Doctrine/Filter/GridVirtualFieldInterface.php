<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine\Filter;

use Doctrine\ORM\QueryBuilder;

/**
 * Extension point for grid fields that have no direct ORM mapping on the
 * resource (computed columns, joined tables, subqueries).
 *
 * Implementations are application-specific. Register them with the
 * `nubit.api_platform.grid_virtual_field` tag (or rely on autoconfiguration
 * when the admin bundle is installed) and DataGridFilter will consult them
 * for filtering, searching, and sorting.
 *
 * The root alias of the resource in the QueryBuilder is always `o`.
 */
interface GridVirtualFieldInterface
{
    public function supports(string $resourceClass, string $field): bool;

    /**
     * DQL expression for the field, used by searchValue and sorting.
     * May add (idempotent) joins to the QueryBuilder. Return null when the
     * field can only be handled through applyFilter() (e.g. subqueries).
     */
    public function expression(QueryBuilder $queryBuilder, string $resourceClass, string $field): ?string;

    /**
     * Applies a grid filter condition for the field.
     *
     * @param string $operator Grid operator (`=`, `contains`, `startswith`, …) —
     *                         translate with GridFilterHelper::dqlOperator().
     */
    public function applyFilter(
        QueryBuilder $queryBuilder,
        string $resourceClass,
        string $field,
        string $operator,
        mixed $value,
    ): void;
}
