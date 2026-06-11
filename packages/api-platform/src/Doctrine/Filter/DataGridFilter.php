<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine\Filter;

use Override;
use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * API Platform filter implementing the Nubit grid contract: `sort`, `filter`,
 * and `searchValue` query parameters as serialized by @nubitio/core.
 *
 * Fields without a direct ORM mapping (computed columns, joins, subqueries)
 * are delegated to GridVirtualFieldInterface implementations tagged with
 * `nubit.api_platform.grid_virtual_field`.
 */
class DataGridFilter extends AbstractFilter
{
    /**
     * @param array<string, mixed>|null            $properties
     * @param iterable<GridVirtualFieldInterface>  $virtualFields
     */
    public function __construct(
        ManagerRegistry $managerRegistry,
        ?LoggerInterface $logger = null,
        ?array $properties = null,
        ?NameConverterInterface $nameConverter = null,
        #[AutowireIterator('nubit.api_platform.grid_virtual_field')]
        private readonly iterable $virtualFields = [],
    ) {
        parent::__construct($managerRegistry, $logger, $properties, $nameConverter);
    }

    /**
     * @inheritdoc
     */
    #[Override]
    public function getDescription(string $resourceClass): array
    {
        return [
            'sort' => [
                'property' => 'sort',
                'type' => 'string',
                'required' => false,
                'description' => 'Sorting parameter.',
            ],
            'filter' => [
                'property' => 'filter',
                'type' => 'array',
                'required' => false,
                'description' => 'Filtering parameter.',
            ],
            'searchValue' => [
                'property' => 'searchValue',
                'type' => 'string',
                'required' => false,
                'description' => 'Search parameter.',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    #[Override]
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if ('sort' === $property) {
            $this->applySort($queryBuilder, $resourceClass, $value);
        }

        if ('filter' === $property) {
            $this->applyFilter($queryBuilder, $resourceClass, $value);
        }

        if ('searchValue' === $property && isset($context['filters']['searchExpr'])) {
            $searchExpr = $context['filters']['searchExpr'];

            if (is_array($searchExpr)) {
                $orX = $queryBuilder->expr()->orX();
                foreach ($searchExpr as $field) {
                    $dqlExpr = $this->resolveFieldExpression($queryBuilder, $resourceClass, $field);
                    $param = GridFilterHelper::uniqueParameterName($queryBuilder, $field);
                    $orX->add(sprintf('%s LIKE :%s', $dqlExpr, $param));
                    $queryBuilder->setParameter($param, sprintf('%%%s%%', $value));
                }

                $queryBuilder->andWhere($orX);
            } else {
                $dqlExpr = $this->resolveFieldExpression($queryBuilder, $resourceClass, $searchExpr);
                $param = GridFilterHelper::uniqueParameterName($queryBuilder, $searchExpr);
                $queryBuilder->andWhere(sprintf('%s LIKE :%s', $dqlExpr, $param));
                $queryBuilder->setParameter($param, sprintf('%%%s%%', $value));
            }
        }
    }

    private function findVirtualField(string $resourceClass, string $field): ?GridVirtualFieldInterface
    {
        foreach ($this->virtualFields as $virtualField) {
            if ($virtualField->supports($resourceClass, $field)) {
                return $virtualField;
            }
        }

        return null;
    }

    /**
     * Resolves a (possibly virtual) field name to its DQL expression.
     * Virtual fields may add their own joins to the QueryBuilder.
     */
    private function resolveFieldExpression(
        QueryBuilder $queryBuilder,
        string $resourceClass,
        string $field,
    ): string {
        $expression = $this->findVirtualField($resourceClass, $field)
            ?->expression($queryBuilder, $resourceClass, $field);

        return $expression ?? sprintf('o.%s', $field);
    }

    /**
     * @param array<int, mixed> $sort
     */
    private function applySort(QueryBuilder $queryBuilder, string $resourceClass, array $sort): void
    {
        foreach ($sort as $sortParam) {
            $field = $sortParam['selector'];
            $isDesc = is_bool($sortParam['desc']) ? $sortParam['desc'] : 'true' === $sortParam['desc'];

            $queryBuilder->addOrderBy(
                $this->resolveFieldExpression($queryBuilder, $resourceClass, $field),
                $isDesc ? 'DESC' : 'ASC'
            );
        }
    }

    private function normalizeRelationIdentifier(mixed $value): mixed
    {
        if (!is_string($value) || '' === $value) {
            return $value;
        }

        if (!str_starts_with($value, '/')) {
            return $value;
        }

        return basename($value);
    }

    /**
     * @param array<int, mixed> $filter
     */
    private function applyFilter(
        QueryBuilder $queryBuilder,
        string $resourceClass,
        array $filter,
    ): void {
        foreach ($filter as $filterParam) {
            if (is_string($filterParam)) {
                continue;
            }

            if (is_array($filterParam[0])) {
                $this->applyFilter($queryBuilder, $resourceClass, $filterParam);
                continue;
            }

            if (!array_key_exists(1, $filterParam)) {
                continue;
            }

            if (!array_key_exists(2, $filterParam) && !in_array($filterParam[1], ['isnull', 'isnotnull'], true)) {
                continue;
            }

            $field = $filterParam[0];
            $op = $filterParam[1];

            $virtualField = $this->findVirtualField($resourceClass, $field);
            if (null !== $virtualField) {
                $virtualField->applyFilter($queryBuilder, $resourceClass, $field, $op, $filterParam[2] ?? null);
                continue;
            }

            if ('isnull' === $op || 'isnotnull' === $op) {
                $operator = GridFilterHelper::dqlOperator($op);
                $queryBuilder->andWhere(
                    sprintf('o.%s %s', $field, $operator)
                );
            } elseif ('in' === $op) {
                $uniqueParameterName = GridFilterHelper::uniqueParameterName(
                    $queryBuilder,
                    $field
                );
                $queryBuilder->andWhere(
                    sprintf('o.%s IN (:%s)', $field, $uniqueParameterName)
                )
                    ->setParameter(
                        $uniqueParameterName,
                        array_map($this->normalizeRelationIdentifier(...), (array) $filterParam[2])
                    );
            } else {
                $operator = GridFilterHelper::dqlOperator($op);
                $rawValue = $filterParam[2] ?? null;
                $value = GridFilterHelper::valueForOperator($op, $this->normalizeRelationIdentifier($rawValue));
                $uniqueParameterName = GridFilterHelper::uniqueParameterName(
                    $queryBuilder,
                    $field
                );
                $queryBuilder->andWhere(
                    sprintf('o.%s %s :%s', $field, $operator, $uniqueParameterName)
                )
                    ->setParameter($uniqueParameterName, $value);
            }
        }
    }
}
