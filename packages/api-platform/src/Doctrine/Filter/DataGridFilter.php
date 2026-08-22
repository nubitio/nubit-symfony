<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Throwable;

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
            $this->applySort($queryBuilder, $resourceClass, self::decodeGridParam($value));
        }

        if ('filter' === $property) {
            $this->applyFilter($queryBuilder, $resourceClass, self::decodeGridParam($value));
        }

        if ('searchValue' === $property && isset($context['filters']['searchExpr'])) {
            $searchExpr = $context['filters']['searchExpr'];

            // searchExpr names the columns to scan and arrives from the client
            // like every other grid parameter, so unknown entries are dropped
            // rather than handed to DQL.
            $fields = array_values(array_filter(
                array_map(strval(...), is_array($searchExpr) ? $searchExpr : [$searchExpr]),
                fn(string $field): bool => $this->isQueryableField($resourceClass, $field),
            ));

            if ([] === $fields) {
                return;
            }

            $orX = $queryBuilder->expr()->orX();
            foreach ($fields as $field) {
                $orX->add($this->searchComparison($queryBuilder, $resourceClass, $field, $value));
            }

            $queryBuilder->andWhere($orX);
        }
    }

    /**
     * Builds one `LIKE` comparison for the global search, and binds its value.
     *
     * Global search runs the same text pattern across every field the resource
     * declares searchable, which routinely includes amounts, dates and enums.
     * PostgreSQL has no `LIKE` for those — `numeric ~~ unknown` aborts the whole
     * request with a 500 — so anything not known to be a string column is
     * concatenated with an empty string first, which every supported platform
     * renders as a text cast. Known string columns are compared directly, so
     * the common case stays index-friendly.
     */
    private function searchComparison(
        QueryBuilder $queryBuilder,
        string $resourceClass,
        string $field,
        mixed $value,
    ): string {
        $dqlExpr = $this->textComparableExpression(
            $this->resolveFieldExpression($queryBuilder, $resourceClass, $field),
            $resourceClass,
            $field,
        );

        $param = GridFilterHelper::uniqueParameterName($queryBuilder, $field);
        $queryBuilder->setParameter($param, sprintf('%%%s%%', $value));

        return sprintf('%s LIKE :%s', $dqlExpr, $param);
    }

    /**
     * Reshapes an expression so `LIKE` can be applied to it.
     *
     * Two shapes break outright. Doctrine rejects `LIKE` on an association
     * ("Invalid PathExpression. Must be a StateFieldPathExpression"), and
     * PostgreSQL rejects it on a numeric or date column ("operator does not
     * exist: numeric ~~ unknown"); either aborts the request with a 500. An
     * association falls back to its identifier — the same value `=` and `in`
     * already match on — and anything not known to be a string column is
     * concatenated with an empty string, which every supported platform renders
     * as a text cast. Known string columns are returned untouched so the common
     * case stays index-friendly.
     */
    private function textComparableExpression(string $expression, string $resourceClass, string $field): string
    {
        $metadata = $this->fieldMetadata($resourceClass);

        if (null !== $metadata && $metadata->hasAssociation($field)) {
            // IDENTITY() is defined for to-one associations only; a to-many has
            // no single value to compare and is left for Doctrine to reject.
            return $metadata->isSingleValuedAssociation($field)
                ? sprintf("CONCAT(IDENTITY(%s), '')", $expression)
                : $expression;
        }

        $isString =
            null !== $metadata
            && null === $this->findVirtualField($resourceClass, $field)
            && $metadata->hasField($field)
            && in_array($metadata->getTypeOfField($field), self::STRING_FIELD_TYPES, true);

        return $isString ? $expression : sprintf("CONCAT(%s, '')", $expression);
    }

    /** Doctrine field types that PostgreSQL already compares with `LIKE`. */
    private const array STRING_FIELD_TYPES = ['string', 'text', 'ascii_string', 'guid'];

    /** @return ClassMetadata<object>|null */
    private function fieldMetadata(string $resourceClass): ?ClassMetadata
    {
        try {
            return $this->getClassMetadata($resourceClass);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * True when the resource can actually be queried on this field.
     *
     * Field names arrive from the query string, so they are attacker-controlled.
     * Passing an unknown one through to DQL raises a Doctrine semantical error
     * and the request ends as a 500 — the same outcome the malformed-parameter
     * handling above already refuses to produce. Unknown fields are dropped
     * instead, consistent with how API Platform's own filters treat properties
     * a resource does not expose.
     *
     * When metadata cannot be read at all (a resource that is not an ORM
     * entity), the field is allowed through so non-Doctrine resources keep
     * working as before.
     */
    private function isQueryableField(string $resourceClass, string $field): bool
    {
        if (null !== $this->findVirtualField($resourceClass, $field)) {
            return true;
        }

        $metadata = $this->fieldMetadata($resourceClass);
        if (null === $metadata) {
            return true;
        }

        return $metadata->hasField($field) || $metadata->hasAssociation($field);
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
    private function resolveFieldExpression(QueryBuilder $queryBuilder, string $resourceClass, string $field): string
    {
        $expression = $this->findVirtualField($resourceClass, $field)?->expression(
            $queryBuilder,
            $resourceClass,
            $field,
        );

        return $expression ?? sprintf('o.%s', $field);
    }

    /**
     * Normalizes a grid query parameter into the array shape the appliers expect.
     *
     * The grid protocol publishes `sort` and `filter` as JSON-encoded strings
     * (contracts/x-grid-protocol.json), while PHP's bracket syntax
     * (`filter[0][0]=name`) arrives already decoded. Both formats reach this
     * filter from real clients, so both are accepted. Anything else yields no
     * criteria — a malformed parameter must not become a 500.
     *
     * @return array<int, mixed>
     */
    private static function decodeGridParam(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array<int, mixed> $sort
     */
    private function applySort(QueryBuilder $queryBuilder, string $resourceClass, array $sort): void
    {
        foreach ($sort as $sortParam) {
            if (is_string($sortParam)) {
                $sortParam = json_decode($sortParam, true);
            }

            if (!is_array($sortParam)) {
                continue;
            }

            $field = $sortParam['selector'] ?? null;
            if (!is_string($field) || '' === $field) {
                continue;
            }

            if (!$this->isQueryableField($resourceClass, $field)) {
                $this->getLogger()->notice('Ignoring grid sort on an unknown field.', [
                    'resource' => $resourceClass,
                    'field' => $field,
                ]);

                continue;
            }

            $desc = $sortParam['desc'] ?? false;
            $isDesc = is_bool($desc) ? $desc : in_array($desc, ['true', '1', 1], true);

            $queryBuilder->addOrderBy(
                $this->resolveFieldExpression($queryBuilder, $resourceClass, $field),
                $isDesc ? 'DESC' : 'ASC',
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
     * True when `$candidate` is a single filter leaf — `[field, operator]` for a
     * unary operator, `[field, operator, value]` otherwise — rather than a list
     * of leaves. The operator position is what distinguishes the two, so a list
     * of JSON-encoded leaves is never mistaken for a leaf.
     *
     * @param array<int, mixed> $candidate
     */
    private static function isFilterLeaf(array $candidate): bool
    {
        if (!isset($candidate[0], $candidate[1]) || !is_string($candidate[0])) {
            return false;
        }

        if (!GridFilterHelper::isOperator($candidate[1])) {
            return false;
        }

        return GridFilterHelper::isUnaryOperator($candidate[1]) ? 2 === count($candidate) : 3 === count($candidate);
    }

    /**
     * @param array<int, mixed> $filter
     */
    private function applyFilter(QueryBuilder $queryBuilder, string $resourceClass, array $filter): void
    {
        // A single leaf may arrive unwrapped (`filter[0]=name&filter[1]==&filter[2]=x`).
        // Wrap it so the loop below always walks a list of criteria.
        if (self::isFilterLeaf($filter)) {
            $filter = [$filter];
        }

        foreach ($filter as $filterParam) {
            if (is_string($filterParam)) {
                // Either a JSON-encoded leaf (the published protocol form) or a
                // boolean connector such as "and"/"or", which carries no criteria.
                $decoded = json_decode($filterParam, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $filterParam = array_values($decoded);
            }

            if (!is_array($filterParam) || !array_key_exists(0, $filterParam)) {
                continue;
            }

            if (is_array($filterParam[0])) {
                $this->applyFilter($queryBuilder, $resourceClass, $filterParam);
                continue;
            }

            if (!array_key_exists(1, $filterParam)) {
                continue;
            }

            if (!array_key_exists(2, $filterParam) && !GridFilterHelper::isUnaryOperator($filterParam[1])) {
                continue;
            }

            $field = (string) $filterParam[0];
            $op = (string) $filterParam[1];

            $virtualField = $this->findVirtualField($resourceClass, $field);
            if (null !== $virtualField) {
                $virtualField->applyFilter($queryBuilder, $resourceClass, $field, $op, $filterParam[2] ?? null);
                continue;
            }

            if (!$this->isQueryableField($resourceClass, $field)) {
                $this->getLogger()->notice('Ignoring grid filter on an unknown field.', [
                    'resource' => $resourceClass,
                    'field' => $field,
                ]);

                continue;
            }

            if ('isnull' === $op || 'isnotnull' === $op) {
                $operator = GridFilterHelper::dqlOperator($op);
                $queryBuilder->andWhere(sprintf('o.%s %s', $field, $operator));
            } elseif ('in' === $op) {
                $uniqueParameterName = GridFilterHelper::uniqueParameterName($queryBuilder, $field);
                $queryBuilder->andWhere(sprintf(
                    'o.%s IN (:%s)',
                    $field,
                    $uniqueParameterName,
                ))->setParameter($uniqueParameterName, array_map(
                    $this->normalizeRelationIdentifier(...),
                    (array) $filterParam[2],
                ));
            } else {
                $operator = GridFilterHelper::dqlOperator($op);
                $rawValue = $filterParam[2] ?? null;
                $value = GridFilterHelper::valueForOperator($op, $this->normalizeRelationIdentifier($rawValue));
                $uniqueParameterName = GridFilterHelper::uniqueParameterName($queryBuilder, $field);
                // Only the LIKE-family needs reshaping; `=`, `<`, `>` and friends
                // compare an association or a number perfectly well as they are.
                $expression =
                    'LIKE' === $operator || 'NOT LIKE' === $operator
                        ? $this->textComparableExpression(sprintf('o.%s', $field), $resourceClass, $field)
                        : sprintf('o.%s', $field);
                $queryBuilder->andWhere(sprintf(
                    '%s %s :%s',
                    $expression,
                    $operator,
                    $uniqueParameterName,
                ))->setParameter($uniqueParameterName, $value);
            }
        }
    }
}
