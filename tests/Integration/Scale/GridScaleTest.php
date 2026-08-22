<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Scale;

use Doctrine\DBAL\Connection;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\ApiPlatform\Doctrine\ApproximateCounter;
use Nubit\Tests\Integration\Fixture\Entity\LedgerEntry;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reading a grid that has grown.
 *
 * Every grid is small on the day it ships. The ones that stop working are the
 * ones nobody decided anything about: page 4,000 of an offset-paginated table
 * asks the database to fetch and discard 80,000 rows, and the footer's
 * `COUNT(*)` walks the relation on top of that.
 */
#[CoversNothing]
final class GridScaleTest extends IntegrationTestCase
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
    }

    // ── Cursor pagination ─────────────────────────────────────────────────

    public function testACursorPaginatedGridWalksTheWholeTableWithoutRepeatingARow(): void
    {
        $this->seed(25);

        $seen = [];
        $url = '/api/ledger_entries?itemsPerPage=10';

        // Followed the way a client must: by the link the server gives, never by
        // an offset the client calculated.
        for ($page = 0; $page < 5 && null !== $url; ++$page) {
            $payload = $this->json($this->get($url));
            foreach ($this->references($payload) as $reference) {
                $seen[] = $reference;
            }

            $url = $this->nextLink($payload);
        }

        self::assertCount(25, $seen, 'The walk repeated or skipped rows.');
        self::assertSame(25, count(array_unique($seen)));
    }

    /**
     * Cursor pagination walks one ordered field. Ordering by anything else makes
     * the comparison stop describing the sequence, so pages silently repeat rows
     * and skip others — the failure nobody notices until a reconciliation comes
     * up short.
     */
    public function testSortingByAnotherFieldIsRefusedRatherThanServedWrong(): void
    {
        $this->seed(3);

        $response = $this->get('/api/ledger_entries?sort=' . urlencode('[{"selector":"account","desc":false}]'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('cursor', (string) $response->getContent());
    }

    public function testSortingByTheCursorFieldIsAllowed(): void
    {
        $this->seed(3);

        $response = $this->get('/api/ledger_entries?sort=' . urlencode('[{"selector":"id","desc":true}]'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    /** A resource that made no such declaration keeps sorting on any column. */
    public function testAnOrdinaryResourceStillSortsFreely(): void
    {
        $response = $this->get('/api/invoices?sort=' . urlencode('[{"selector":"customer","desc":false}]'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    /** Partial pagination means no total: a client must not render one as precise. */
    public function testAPartiallyPaginatedGridPublishesNoTotal(): void
    {
        $this->seed(5);

        $payload = $this->json($this->get('/api/ledger_entries?itemsPerPage=2'));

        self::assertArrayNotHasKey('hydra:totalItems', $payload);
        self::assertArrayNotHasKey('totalItems', $payload);
    }

    // ── The contract says how to read it ──────────────────────────────────

    public function testTheDocumentationPublishesHowToPaginate(): void
    {
        $scale = $this->gridScaleFor('LedgerEntry');

        self::assertIsArray($scale);
        self::assertSame('cursor', $scale['mode']);
        self::assertSame('id', $scale['cursorField']);
        self::assertSame('DESC', $scale['cursorDirection']);
        self::assertFalse($scale['exactCount']);
        self::assertSame(['id'], $scale['sortableFields']);
    }

    /** Read from what is in force, not only from the attribute. */
    public function testPartialPaginationOverridesTheAttributesClaimOfAnExactCount(): void
    {
        // The fixture declares exactCount: false and paginationPartial: true;
        // either alone must be enough to stop a client trusting the total.
        self::assertFalse($this->gridScaleFor('LedgerEntry')['exactCount']);
    }

    /**
     * The estimate reaches the client as a header on a partially paginated
     * collection — which is the only place it is useful, since that is where the
     * exact total was given up.
     */
    public function testAPartiallyPaginatedCollectionCarriesAnEstimate(): void
    {
        $this->seed(300);
        $this->connection()->executeStatement('ANALYZE fixture_ledger_entry');

        $response = $this->get('/api/ledger_entries?itemsPerPage=10');

        self::assertNotNull($response->headers->get('X-Estimated-Count'));
        self::assertGreaterThan(0, (int) $response->headers->get('X-Estimated-Count'));
    }

    /**
     * A filtered count cannot come from table statistics, and a number that
     * quietly ignored the filter would be worse than no number at all.
     */
    public function testAFilteredCollectionCarriesNoEstimate(): void
    {
        $this->seed(300);
        $this->connection()->executeStatement('ANALYZE fixture_ledger_entry');

        $response = $this->get('/api/ledger_entries?filter=' . urlencode('["account","=","ACC-1"]'));

        self::assertNull($response->headers->get('X-Estimated-Count'));
    }

    // ── Approximate counting ──────────────────────────────────────────────

    public function testTheEstimateAnswersFromPlannerStatistics(): void
    {
        $this->seed(300);

        // The estimate is only as fresh as the last analyse; asking for one
        // without analysing would be testing autovacuum's schedule.
        $this->connection()->executeStatement('ANALYZE fixture_ledger_entry');

        $estimate = $this->counter()->estimate('fixture_ledger_entry');

        self::assertNotNull($estimate);
        // A few percent either way is the trade; being wrong by an order of
        // magnitude would not be.
        self::assertGreaterThan(150, $estimate);
        self::assertLessThan(600, $estimate);
    }

    /**
     * A table nobody has analysed reports -1, and an empty one reports 0. Both
     * have to read as "count it properly" rather than as a number.
     */
    public function testAnUnanalysedTableYieldsNoEstimate(): void
    {
        self::assertNull($this->counter()->estimate('fixture_ledger_entry'));
    }

    public function testAnUnknownTableYieldsNoEstimate(): void
    {
        self::assertNull($this->counter()->estimate('table_that_does_not_exist'));
    }

    public function testAnIdentifierThatIsNotAPlainNameIsRefused(): void
    {
        self::assertNull($this->counter()->estimate('fixture_ledger_entry; DROP TABLE fixture_ledger_entry'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function counter(): ApproximateCounter
    {
        $counter = $this->container()->get(ApproximateCounter::class);
        self::assertInstanceOf(ApproximateCounter::class, $counter);

        return $counter;
    }

    private function connection(): Connection
    {
        $connection = $this->entityManager()->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /** @return array<string, mixed> */
    private function gridScaleFor(string $shortName): array
    {
        $payload = $this->json($this->get('/api/docs.jsonld'));
        $classes = $payload['hydra:supportedClass'] ?? $payload['supportedClass'] ?? [];
        self::assertIsArray($classes);

        foreach ($classes as $class) {
            if (!is_array($class)) {
                continue;
            }

            if (($class['hydra:title'] ?? $class['title'] ?? null) === $shortName) {
                $scale = $class['x-grid-scale'] ?? null;
                self::assertIsArray($scale, sprintf('%s publishes no x-grid-scale.', $shortName));

                $typed = [];
                /** @var mixed $value */
                foreach ($scale as $key => $value) {
                    $typed[(string) $key] = $value;
                }

                return $typed;
            }
        }

        self::fail(sprintf('%s is not in the documentation.', $shortName));
    }

    /** @param array<string, mixed> $payload */
    private function nextLink(array $payload): ?string
    {
        $view = $payload['hydra:view'] ?? $payload['view'] ?? null;
        if (!is_array($view)) {
            return null;
        }

        $next = $view['hydra:next'] ?? $view['next'] ?? null;

        return is_string($next) ? $next : null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function references(array $payload): array
    {
        $members = $payload['hydra:member'] ?? $payload['member'] ?? [];
        self::assertIsArray($members);

        $references = [];
        foreach ($members as $member) {
            self::assertIsArray($member);
            self::assertArrayHasKey('reference', $member);
            self::assertIsString($member['reference']);
            $references[] = $member['reference'];
        }

        return $references;
    }

    private function seed(int $count): void
    {
        $entityManager = $this->entityManager();

        for ($i = 1; $i <= $count; ++$i) {
            $entry = new LedgerEntry();
            $entry->reference = sprintf('L-%04d', $i);
            $entry->account = sprintf('ACC-%d', $i % 5);
            $entityManager->persist($entry);

            if (0 === ($i % 100)) {
                $entityManager->flush();
                $entityManager->clear();
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function get(string $path): Response
    {
        $this->entityManager()->clear();

        $request = Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/ld+json']);

        if (null === $this->kernel) {
            self::fail('Boot the kernel before issuing requests.');
        }

        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
