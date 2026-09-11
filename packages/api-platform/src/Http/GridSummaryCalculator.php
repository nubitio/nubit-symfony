<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Http;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Computes aggregate totals for grid columns marked with
 * {@code openapiContext: ['x-crud' => ['summable' => true]]}.
 *
 * Runs on the same filtered entity manager as the collection query (tenant
 * filters and soft-delete apply automatically), and — critically — applies
 * the same grid filters (`filter`, `searchValue`) the request used to build
 * the collection. Without that, a filtered grid would show row data that
 * matches the filter next to a summary total computed over every row: a
 * total that visibly disagrees with what is on screen.
 */
final readonly class GridSummaryCalculator
{
    /** Query builder alias every grid filter (and grid virtual field) assumes the root entity is bound to. */
    private const string ROOT_ALIAS = 'o';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropertyMetadataFactoryInterface $propertyMetadataFactory,
        private PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory,
        private ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
        private ?ContainerInterface $filterLocator = null,
    ) {}

    /**
     * @return array<string, string|int|float>
     */
    public function compute(string $resourceClass, Request $request): array
    {
        if (!$this->isSummaryEnabled($resourceClass)) {
            return [];
        }

        $fields = $this->resolveSummableFields($resourceClass);
        if ($fields === []) {
            return [];
        }

        $selects = [];
        foreach ($fields as $property => $summaryType) {
            $dqlField = self::ROOT_ALIAS . '.' . $property;
            $selects[] = match ($summaryType) {
                'count' => sprintf('COUNT(%s) AS %s_summary', $dqlField, $property),
                'avg' => sprintf('AVG(%s) AS %s_summary', $dqlField, $property),
                'min' => sprintf('MIN(%s) AS %s_summary', $dqlField, $property),
                'max' => sprintf('MAX(%s) AS %s_summary', $dqlField, $property),
                default => sprintf('SUM(%s) AS %s_summary', $dqlField, $property),
            };
        }

        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select(implode(', ', $selects))
            ->from($resourceClass, self::ROOT_ALIAS);

        $this->applyGridFilters($qb, $resourceClass, $request);

        /** @var array<string, mixed> $row */
        $row = $qb->getQuery()->getSingleResult();

        $summary = [];
        foreach ($fields as $property => $_) {
            $value = $row[$property . '_summary'] ?? null;
            if ($value === null) {
                continue;
            }
            $summary[$property] = \is_string($value) ? $value : (is_numeric($value) ? (string) $value : $value);
        }

        return $summary;
    }

    private function isSummaryEnabled(string $resourceClass): bool
    {
        foreach ($this->resourceMetadataCollectionFactory->create($resourceClass) as $metadata) {
            $extra = $metadata->getExtraProperties()['x-crud'] ?? null;
            if (\is_array($extra) && ($extra['summary'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function resolveSummableFields(string $resourceClass): array
    {
        $fields = [];
        foreach ($this->propertyNameCollectionFactory->create($resourceClass) as $propertyName) {
            try {
                $propertyMetadata = $this->propertyMetadataFactory->create($resourceClass, $propertyName);
            } catch (\Throwable) {
                continue;
            }

            $openapiContext = $propertyMetadata->getOpenapiContext();
            if (!\is_array($openapiContext)) {
                continue;
            }

            $crud = $openapiContext['x-crud'] ?? null;
            if (!\is_array($crud) || !($crud['summable'] ?? false)) {
                continue;
            }

            $summaryType = $crud['summaryType'] ?? 'sum';
            $fields[$propertyName] = \is_string($summaryType) ? $summaryType : 'sum';
        }

        return $fields;
    }

    /**
     * Applies the resource's configured collection filters (`DataGridFilter`
     * among them) to the summary query, the same way
     * `ApiPlatform\Doctrine\Orm\Extension\FilterExtension` applies them to the
     * collection query — same filter services, same request parameters — so a
     * filtered grid's footer total is computed over the rows the grid shows,
     * not the whole table.
     *
     * Ordering has no meaning for a single aggregate row and this query has no
     * `GROUP BY`, so any `ORDER BY` a filter adds (grid `sort`, or an
     * unrelated `OrderFilter`) would make PostgreSQL reject the query outright
     * ("column ... must appear in the GROUP BY clause"). It is stripped
     * afterwards rather than guessed at by parameter name, so this stays
     * correct regardless of which filters a resource configures.
     */
    private function applyGridFilters(QueryBuilder $queryBuilder, string $resourceClass, Request $request): void
    {
        if (null === $this->filterLocator) {
            return;
        }

        $operation = $request->attributes->get('_api_operation');
        if (!$operation instanceof Operation) {
            return;
        }

        $filterIds = $operation->getFilters() ?? [];
        if ([] === $filterIds) {
            return;
        }

        $filters = $request->attributes->get('_api_filters');
        if (!\is_array($filters)) {
            $filters = $request->query->all();
        }

        /** @var array<string, mixed> $filters */
        $context = ['filters' => $filters];

        $queryNameGenerator = new QueryNameGenerator();

        foreach ($filterIds as $filterId) {
            if (!\is_string($filterId) || !$this->filterLocator->has($filterId)) {
                continue;
            }

            $filter = $this->filterLocator->get($filterId);
            if ($filter instanceof FilterInterface) {
                $filter->apply($queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);
            }
        }

        $queryBuilder->resetDQLPart('orderBy');
    }
}
