<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Tests\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the wire formats of the grid query protocol.
 *
 * `sort` and `filter` reach the filter in two shapes: PHP bracket syntax
 * (`filter[0][0]=name`), which arrives pre-decoded, and the JSON-encoded string
 * published in contracts/x-grid-protocol.json. Both must produce the same DQL —
 * a format the backend silently drops shows the grid unfiltered data.
 */
final class DataGridFilterTest extends TestCase
{
    // ── sort ──────────────────────────────────────────────────────────────

    /** @return iterable<string, array{mixed, string}> */
    public static function sortCases(): iterable
    {
        yield 'bracket array, descending' => [
            [['selector' => 'name', 'desc' => true]],
            'ORDER BY o.name DESC',
        ];

        yield 'bracket array, ascending' => [
            [['selector' => 'name', 'desc' => false]],
            'ORDER BY o.name ASC',
        ];

        // Query strings carry no booleans: `sort[0][desc]=true` arrives as "true".
        yield 'bracket array, desc as query-string text' => [
            [['selector' => 'name', 'desc' => 'true']],
            'ORDER BY o.name DESC',
        ];

        yield 'JSON string, the published protocol form' => [
            '[{"selector":"name","desc":true}]',
            'ORDER BY o.name DESC',
        ];

        yield 'multiple descriptors keep their order' => [
            '[{"selector":"segment","desc":false},{"selector":"name","desc":true}]',
            'ORDER BY o.segment ASC, o.name DESC',
        ];
    }

    #[DataProvider('sortCases')]
    public function testSortAcceptsEveryPublishedWireFormat(mixed $sort, string $expectedDql): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter()->applyGridParam($queryBuilder, 'sort', $sort);

        self::assertStringContainsString($expectedDql, $queryBuilder->getDQL());
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedSortCases(): iterable
    {
        yield 'string that is not JSON' => ['not-json'];
        yield 'JSON scalar' => ['42'];
        yield 'descriptor without a selector' => [[['desc' => true]]];
        yield 'descriptor with an empty selector' => [[['selector' => '', 'desc' => true]]];
        yield 'null' => [null];
    }

    /**
     * A malformed `sort` used to reach `applySort(array $sort)` as a string and
     * raise a TypeError, which API Platform surfaced as a 500.
     */
    #[DataProvider('malformedSortCases')]
    public function testMalformedSortIsIgnoredInsteadOfFailing(mixed $sort): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter()->applyGridParam($queryBuilder, 'sort', $sort);

        self::assertStringNotContainsString('ORDER BY', $queryBuilder->getDQL());
    }

    // ── filter ────────────────────────────────────────────────────────────

    /** @return iterable<string, array{mixed}> */
    public static function equivalentFilterFormats(): iterable
    {
        yield 'nested bracket array' => [[['name', 'contains', 'Acme']]];
        yield 'unwrapped single leaf' => [['name', 'contains', 'Acme']];
        yield 'JSON string leaf' => ['["name","contains","Acme"]'];
        yield 'list holding a JSON string leaf' => [['["name","contains","Acme"]']];
    }

    /**
     * Every one of these formats is produced by a supported client — the nested
     * form by HydraRemoteDataSource, the JSON forms by its public
     * `makeFilterRules()` and by the published protocol schema.
     */
    #[DataProvider('equivalentFilterFormats')]
    public function testEveryFilterFormatProducesTheSameCriterion(mixed $filter): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter()->applyGridParam($queryBuilder, 'filter', $filter);

        self::assertStringContainsString('o.name LIKE :name', $queryBuilder->getDQL());
        self::assertSame('%Acme%', $queryBuilder->getParameter('name')?->getValue());
    }

    public function testBooleanConnectorsBetweenLeavesAreSkipped(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter()->applyGridParam($queryBuilder, 'filter', [
            ['name', 'contains', 'Acme'],
            'and',
            ['segment', '=', 'retail'],
        ]);

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString('o.name LIKE :name', $dql);
        self::assertStringContainsString('o.segment = :segment', $dql);
        self::assertSame('retail', $queryBuilder->getParameter('segment')?->getValue());
    }

    public function testUnaryOperatorBindsNoValue(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter()->applyGridParam($queryBuilder, 'filter', ['deletedAt', 'isnull']);

        self::assertStringContainsString('o.deletedAt IS NULL', $queryBuilder->getDQL());
        self::assertNull($queryBuilder->getParameter('deletedAt'));
    }

    /**
     * Three JSON-encoded leaves must not be mistaken for one unwrapped leaf:
     * the operator position is what tells the two shapes apart.
     */
    public function testListOfThreeJsonLeavesIsNotReadAsASingleLeaf(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter()
            ->applyGridParam(
                $queryBuilder,
                'filter',
                [
                    '["name","contains","Acme"]',
                    '["segment","=","retail"]',
                    '["email","endswith",".com"]',
                ],
            );

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString('o.name LIKE :name', $dql);
        self::assertStringContainsString('o.segment = :segment', $dql);
        self::assertStringContainsString('o.email LIKE :email', $dql);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedFilterCases(): iterable
    {
        yield 'string that is not JSON' => ['not-json'];
        yield 'leaf without an operator' => [[['name']]];
        yield 'binary operator without a value' => [[['name', 'contains']]];
        yield 'null' => [null];
    }

    #[DataProvider('malformedFilterCases')]
    public function testMalformedFilterIsIgnoredInsteadOfFailing(mixed $filter): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter()->applyGridParam($queryBuilder, 'filter', $filter);

        self::assertNull($queryBuilder->getDQLPart('where'));
    }

    // ── searchValue ───────────────────────────────────────────────────────

    /**
     * Global search applies one text pattern across every searchable field.
     * PostgreSQL has no `LIKE` for numeric, date or boolean columns, so a
     * non-string field must be cast before comparison — without it the whole
     * request died with `operator does not exist: numeric ~~ unknown`.
     */
    public function testNonStringSearchFieldsAreCastBeforeComparison(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(['depositAmount' => 'decimal'])->applyGridParam($queryBuilder, 'searchValue', 'zzz', [
            'filters' => ['searchExpr' => ['depositAmount']],
        ]);

        self::assertStringContainsString("CONCAT(o.depositAmount, '') LIKE :depositAmount", $queryBuilder->getDQL());
        self::assertSame('%zzz%', $queryBuilder->getParameter('depositAmount')?->getValue());
    }

    public function testStringSearchFieldsAreComparedDirectly(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(['name' => 'string'])->applyGridParam($queryBuilder, 'searchValue', 'acme', [
            'filters' => ['searchExpr' => ['name']],
        ]);

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString('o.name LIKE :name', $dql);
        self::assertStringNotContainsString('CONCAT', $dql);
    }

    public function testEverySearchFieldIsOredTogether(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(['name' => 'string', 'depositAmount' => 'decimal'])->applyGridParam(
            $queryBuilder,
            'searchValue',
            'x',
            ['filters' => ['searchExpr' => ['name', 'depositAmount']]],
        );

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString('o.name LIKE :name', $dql);
        self::assertStringContainsString("CONCAT(o.depositAmount, '') LIKE :depositAmount", $dql);
        self::assertStringContainsString(' OR ', $dql);
    }

    public function testASingleSearchFieldNeedsNoList(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(['createdAt' => 'datetime'])->applyGridParam($queryBuilder, 'searchValue', '2026', ['filters' => [
            'searchExpr' => 'createdAt',
        ]]);

        self::assertStringContainsString("CONCAT(o.createdAt, '') LIKE :createdAt", $queryBuilder->getDQL());
    }

    /**
     * A field the resource does not have is dropped, not cast.
     *
     * The earlier behaviour wrapped it in `CONCAT` and passed it through, which
     * reads as defensive but is not: `o.whatever` is a Doctrine semantical error
     * with or without the cast, so against a real database the request ended as
     * a 500. Field names come from the query string, so that was a 500 any
     * client could trigger at will.
     */
    public function testAnUnmappedSearchFieldIsDropped(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter([])->applyGridParam($queryBuilder, 'searchValue', 'x', [
            'filters' => ['searchExpr' => ['whatever']],
        ]);

        self::assertNull($queryBuilder->getDQLPart('where'));
    }

    /** Known fields still apply when an unknown one is listed alongside them. */
    public function testUnknownSearchFieldsDoNotDiscardTheKnownOnes(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(['name' => 'string'])->applyGridParam($queryBuilder, 'searchValue', 'acme', [
            'filters' => ['searchExpr' => ['name', 'whatever']],
        ]);

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString('o.name LIKE :name', $dql);
        self::assertStringNotContainsString('whatever', $dql);
    }

    public function testAnUnknownFilterFieldIsDropped(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter()->applyGridParam($queryBuilder, 'filter', '["whatever","=","x"]');

        self::assertNull($queryBuilder->getDQLPart('where'));
    }

    public function testAnUnknownSortFieldIsDropped(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter()->applyGridParam($queryBuilder, 'sort', '[{"selector":"whatever","desc":true}]');

        self::assertEmpty($queryBuilder->getDQLPart('orderBy'));
    }

    /** Booleans survive the round trip: a checkbox column filters on `true`, not `"1"`. */
    public function testBooleanFilterValueIsBoundAsABoolean(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(['paid' => 'boolean'])->applyGridParam($queryBuilder, 'filter', '["paid","=",true]');

        $parameter = $queryBuilder->getParameter('paid');
        self::assertNotNull($parameter);
        self::assertTrue($parameter->getValue());
    }

    public function testSearchWithoutASearchExprIsIgnored(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(['name' => 'string'])->applyGridParam($queryBuilder, 'searchValue', 'acme');

        self::assertNull($queryBuilder->getDQLPart('where'));
    }

    // ── relaciones ────────────────────────────────────────────────────────

    /**
     * A grid column backed by a relation offers the same text operators as any
     * other column. `o.unit LIKE :unit` is not valid DQL — Doctrine answers
     * "Invalid PathExpression. Must be a StateFieldPathExpression" and the
     * request 500s — so the identifier is compared instead.
     */
    public function testTextOperatorsOnARelationCompareItsIdentifier(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(associations: ['unit' => true])->applyGridParam(
            $queryBuilder,
            'filter',
            ['unit', 'contains', '7'],
        );

        self::assertStringContainsString("CONCAT(IDENTITY(o.unit), '') LIKE :unit", $queryBuilder->getDQL());
        self::assertSame('%7%', $queryBuilder->getParameter('unit')?->getValue());
    }

    /** Equality already compares identifiers, so it is left exactly as it was. */
    public function testEqualityOnARelationIsUnchanged(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(associations: ['unit' => true])->applyGridParam(
            $queryBuilder,
            'filter',
            ['unit', '=', '/api/units/7'],
        );

        $dql = $queryBuilder->getDQL();
        self::assertStringContainsString('o.unit = :unit', $dql);
        self::assertStringNotContainsString('IDENTITY', $dql);
        self::assertSame('7', $queryBuilder->getParameter('unit')?->getValue());
    }

    public function testGlobalSearchOverARelationAlsoUsesItsIdentifier(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(associations: ['unit' => true])->applyGridParam($queryBuilder, 'searchValue', '7', [
            'filters' => ['searchExpr' => ['unit']],
        ]);

        self::assertStringContainsString("CONCAT(IDENTITY(o.unit), '') LIKE :unit", $queryBuilder->getDQL());
    }

    /** IDENTITY() has no meaning for a collection, so it is not applied. */
    public function testAToManyRelationIsNotWrappedInIdentity(): void
    {
        $queryBuilder = self::queryBuilder();

        self::filter(associations: ['lines' => false])->applyGridParam($queryBuilder, 'searchValue', 'x', [
            'filters' => ['searchExpr' => ['lines']],
        ]);

        self::assertStringNotContainsString('IDENTITY', $queryBuilder->getDQL());
    }

    // ── harness ───────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $fieldTypes   Doctrine field name => type.
     * @param array<string, bool>   $associations Association name => is to-one.
     */
    private static function filter(?array $fieldTypes = null, array $associations = []): DataGridFilterHarness
    {
        // The Customer stand-in these tests filter against. Passing an explicit
        // map (including []) models a resource whose metadata says otherwise.
        $fieldTypes ??= [
            'name' => 'string',
            'segment' => 'string',
            'email' => 'string',
            'deletedAt' => 'datetime',
        ];

        $harness = new DataGridFilterHarness(self::createStub(ManagerRegistry::class));
        $harness->fieldTypes = $fieldTypes;
        $harness->associations = $associations;

        return $harness;
    }

    private static function queryBuilder(): QueryBuilder
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getExpressionBuilder')->willReturn(new Expr());

        return (new QueryBuilder($entityManager))
            ->select('o')
            ->from('Customer', 'o');
    }
}

/** Exposes the protected `filterProperty()` seam the appliers hang off. */
final class DataGridFilterHarness extends DataGridFilter
{
    /** @var array<string, string> Doctrine field name => type, standing in for real ORM metadata. */
    public array $fieldTypes = [];

    /** @var array<string, bool> Association name => is a to-one association. */
    public array $associations = [];

    protected function getClassMetadata(string $resourceClass): ClassMetadata
    {
        return new FakeClassMetadata($resourceClass, $this->fieldTypes, $this->associations);
    }

    /** @param array<string, mixed> $context */
    public function applyGridParam(
        QueryBuilder $queryBuilder,
        string $property,
        mixed $value,
        array $context = [],
    ): void {
        $this->filterProperty(
            $property,
            $value,
            $queryBuilder,
            self::createNameGenerator(),
            'Customer',
            null,
            $context,
        );
    }

    private static function createNameGenerator(): QueryNameGeneratorInterface
    {
        return new class implements QueryNameGeneratorInterface {
            public function generateJoinAlias(string $association): string
            {
                return $association . '_a1';
            }

            public function generateParameterName(string $name): string
            {
                return $name . '_p1';
            }
        };
    }
}

/**
 * Minimal ORM metadata stand-in: only the four lookups DataGridFilter performs.
 *
 * @extends ClassMetadata<object>
 */
final class FakeClassMetadata extends ClassMetadata
{
    /**
     * @param array<string, string> $types     Field name => Doctrine type.
     * @param array<string, bool>   $relations Association name => is to-one.
     */
    public function __construct(
        string $name,
        private readonly array $types,
        private readonly array $relations,
    ) {
        parent::__construct($name);
    }

    public function hasField($fieldName): bool
    {
        return isset($this->types[$fieldName]);
    }

    public function getTypeOfField($fieldName): ?string
    {
        return $this->types[$fieldName] ?? null;
    }

    public function hasAssociation($fieldName): bool
    {
        return isset($this->relations[$fieldName]);
    }

    public function isSingleValuedAssociation($fieldName): bool
    {
        return $this->relations[$fieldName] ?? false;
    }
}
