<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\ApiPlatform;

use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Tests\Integration\Fixture\Entity\Invoice;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The grid contract against a real PostgreSQL server.
 *
 * `DataGridFilter` builds DQL, and DQL only fails when a database is asked to
 * run it. Its known failure modes — `LIKE` against a numeric or date column,
 * `LIKE` against an association — are invisible to any test that stops at the
 * generated string.
 */
#[CoversNothing]
final class DataGridFilterTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                ],
            ],
            self::fixtureMapping(),
        );

        $this->resetSchema();
        $this->seed();
    }

    public function testUnfilteredCollectionReturnsEverything(): void
    {
        self::assertSame(['A-001', 'A-002', 'B-001'], $this->numbers($this->grid()));
    }

    public function testSortDescending(): void
    {
        $numbers = $this->numbers($this->grid([
            'sort' => json_encode([['selector' => 'number', 'desc' => true]], JSON_THROW_ON_ERROR),
        ]));

        self::assertSame(['B-001', 'A-002', 'A-001'], $numbers);
    }

    /** Sorting on a decimal must order numerically, not lexicographically. */
    public function testSortOnDecimalIsNumeric(): void
    {
        $numbers = $this->numbers($this->grid([
            'sort' => json_encode([['selector' => 'total', 'desc' => false]], JSON_THROW_ON_ERROR),
        ]));

        // 9.50 < 100.00 < 1000.00 — string ordering would put 100.00 first.
        self::assertSame(['B-001', 'A-001', 'A-002'], $numbers);
    }

    /** @return iterable<string, array{array<int, mixed>, list<string>}> */
    public static function filterProvider(): iterable
    {
        yield 'equals on a string' => [['customer', '=', 'Acme'], ['A-001', 'A-002']];
        yield 'contains' => [['number', 'contains', 'A-'], ['A-001', 'A-002']];
        yield 'startswith' => [['number', 'startswith', 'B'], ['B-001']];
        yield 'endswith' => [['number', 'endswith', '002'], ['A-002']];
        yield 'not equals' => [['customer', '<>', 'Acme'], ['B-001']];
        yield 'greater than on a decimal' => [['total', '>', '99'], ['A-001', 'A-002']];
        yield 'less or equal on a decimal' => [['total', '<=', '100.00'], ['A-001', 'B-001']];
        yield 'isnull' => [['status', 'isnull'], ['A-002']];
        yield 'isnotnull' => [
            ['status', 'isnotnull'],
            ['A-001',  'B-001'],
        ];
        yield 'in' => [['number', 'in', ['A-001', 'B-001']], ['A-001', 'B-001']];
        yield 'boolean equals' => [['paid', '=', true], ['A-001']];
        yield 'date comparison' => [['issuedAt', '>=', '2026-02-01'], ['A-002', 'B-001']];
    }

    /**
     * @param array<int, mixed> $leaf
     * @param list<string>      $expected
     */
    #[DataProvider('filterProvider')]
    public function testFilterOperator(array $leaf, array $expected): void
    {
        $numbers = $this->numbers($this->grid(['filter' => json_encode($leaf, JSON_THROW_ON_ERROR)]));

        sort($numbers);
        self::assertSame($expected, $numbers);
    }

    /** Two leaves joined by "and" — the shape the grid sends for a compound filter. */
    public function testCompoundFilter(): void
    {
        $filter = json_encode([['customer', '=', 'Acme'], 'and', ['total', '>', '99']], JSON_THROW_ON_ERROR);

        self::assertSame(['A-001', 'A-002'], $this->numbers($this->grid(['filter' => $filter])));
    }

    /**
     * Global search across a mixed field list. This is the case that produced
     * `operator does not exist: numeric ~~ unknown` in production: the same text
     * pattern is applied to a string, a decimal, a date and an association.
     */
    public function testGlobalSearchAcrossMixedColumnTypes(): void
    {
        $response = $this->grid([
            'searchValue' => 'Acme',
            'searchExpr' => ['number', 'customer', 'total', 'issuedAt', 'paid', 'currency'],
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['A-001', 'A-002'], $this->numbers($response));
    }

    /** Searching a numeric value has to match the numeric column, cast and all. */
    public function testGlobalSearchMatchesANumericColumn(): void
    {
        $response = $this->grid([
            'searchValue' => '1000.00',
            'searchExpr' => ['number', 'total'],
        ]);

        self::assertSame(['A-002'], $this->numbers($response));
    }

    /** A malformed parameter must yield no criteria, never a 500. */
    public function testMalformedFilterIsIgnoredRatherThanFatal(): void
    {
        $response = $this->grid(['filter' => 'not-json-at-all']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertCount(3, $this->numbers($response));
    }

    public function testUnknownFieldDoesNotProduceAServerError(): void
    {
        $response = $this->grid([
            'filter' => json_encode(['nonexistentField', '=', 'x'], JSON_THROW_ON_ERROR),
        ]);

        self::assertLessThan(
            500,
            $response->getStatusCode(),
            'An unknown grid field must be refused, not crash the request: ' . (string) $response->getContent(),
        );
    }

    /** @param array<string, mixed> $query */
    private function grid(array $query = []): Response
    {
        $this->entityManager()->clear();

        $request = Request::create(
            '/api/invoices',
            'GET',
            $query,
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/ld+json',
            ],
        );

        if (null === $this->kernel) {
            self::fail('Boot the kernel before issuing requests.');
        }

        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /** @return list<string> */
    private function numbers(Response $response): array
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $members = $payload['hydra:member'] ?? $payload['member'] ?? null;
        self::assertIsArray($members);

        $numbers = [];
        foreach ($members as $member) {
            self::assertIsArray($member);
            self::assertArrayHasKey('number', $member);
            self::assertIsString($member['number']);
            $numbers[] = $member['number'];
        }

        return $numbers;
    }

    private function seed(): void
    {
        $entityManager = $this->entityManager();

        $rows = [
            ['A-001', 'Acme',   '100.00',  '2026-01-15', true,  'issued'],
            ['A-002', 'Acme',   '1000.00', '2026-02-20', false, null],
            ['B-001', 'Globex', '9.50',    '2026-03-05', false, 'draft'],
        ];

        foreach ($rows as [$number, $customer, $total, $issuedAt, $paid, $status]) {
            $invoice = new Invoice();
            $invoice->number = $number;
            $invoice->customer = $customer;
            $invoice->total = $total;
            $invoice->issuedAt = new \DateTimeImmutable($issuedAt);
            $invoice->paid = $paid;
            $invoice->status = $status;
            $entityManager->persist($invoice);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
