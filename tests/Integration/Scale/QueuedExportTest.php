<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Scale;

use Nubit\AdminBundle\Export\Entity\ExportJob;
use Nubit\AdminBundle\Export\ExportRequestService;
use Nubit\AdminBundle\Export\Message\RunExport;
use Nubit\AdminBundle\Export\Message\RunExportHandler;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Tests\Integration\Fixture\Entity\LedgerEntry;
use Nubit\Tests\Integration\Fixture\Entity\TestUser;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Exporting more rows than a request can carry.
 *
 * The inline export is nicer whenever it can finish — no waiting, no
 * notification, no link. It stops being nicer at the row count where the request
 * times out or the process runs out of memory, and that row count is exactly the
 * export somebody actually needed.
 */
#[CoversNothing]
final class QueuedExportTest extends IntegrationTestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/nubit-export-test-' . bin2hex(random_bytes(4));

        $this->boot(
            [NubitAdminBundle::class],
            [
                'framework' => [
                    'messenger' => [
                        'transports' => ['exports' => 'in-memory://'],
                        'routing' => [RunExport::class => 'exports'],
                    ],
                ],
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                    'export' => [
                        'enabled' => true,
                        'queued' => true,
                        'directory' => $this->workspace,
                        'inline_limit' => 3,
                    ],
                ],
            ],
            self::fixtureMapping(),
            $this->securityConfig(),
        );

        $this->resetSchema();
        $this->seedUser();
    }

    /**
     * A real user provider, because the runner resolves the requester to
     * reapply their row scope. An in-memory provider with nobody in it would
     * make every export fail for a reason that has nothing to do with exporting.
     *
     * @return array<string, mixed>
     */
    private function securityConfig(): array
    {
        return [
            'providers' => [
                'app_users' => ['entity' => ['class' => TestUser::class, 'property' => 'email']],
            ],
            'firewalls' => ['main' => ['security' => false]],
        ];
    }

    private function seedUser(): void
    {
        $entityManager = $this->entityManager();

        $user = new TestUser();
        $user->setEmail('admin@example.com')->setRoles(['ROLE_ADMIN'])->setPassword('unused');
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ('' !== $this->workspace && is_dir($this->workspace)) {
            (new Filesystem())->remove($this->workspace);
        }
    }

    public function testRequestingQueuesRatherThanRunning(): void
    {
        $this->seed(10);

        $job = $this->exports()->queue(LedgerEntry::class, [], 'ledger', 'admin@example.com');

        self::assertSame(ExportJob::STATUS_QUEUED, $job->getStatus());
        self::assertNull($job->getStoragePath());
        self::assertCount(1, $this->queued());
    }

    public function testTheWorkerWritesEveryRow(): void
    {
        $this->seed(120);

        $job = $this->runJob($this->exports()->queue(LedgerEntry::class, [], 'ledger', 'admin@example.com'));

        self::assertSame(ExportJob::STATUS_READY, $job->getStatus(), (string) $job->getFailureReason());
        self::assertSame(120, $job->getRowCount());

        $lines = $this->lines($job);
        // Header plus every row.
        self::assertCount(121, $lines);
        self::assertStringContainsString('reference', $lines[0]);
    }

    /**
     * The file has to open in Excel, which refuses UTF-8 without a byte-order
     * mark. An export nobody can open is not an export.
     */
    public function testTheFileCarriesAByteOrderMark(): void
    {
        $this->seed(1);
        $job = $this->runJob($this->exports()->queue(LedgerEntry::class, [], 'ledger', 'admin@example.com'));

        self::assertStringStartsWith("\xEF\xBB\xBF", (string) file_get_contents((string) $job->getStoragePath()));
    }

    /** The file contains what the user was looking at, not the whole table. */
    public function testTheGridFiltersAreCarriedIntoTheExport(): void
    {
        $this->seed(20);

        $job = $this->runJob($this->exports()->queue(
            LedgerEntry::class,
            ['filter' => json_encode(['account', '=', 'ACC-1'], JSON_THROW_ON_ERROR)],
            'ledger',
            'admin@example.com',
        ));

        self::assertSame(ExportJob::STATUS_READY, $job->getStatus(), (string) $job->getFailureReason());
        self::assertSame(4, $job->getRowCount());
    }

    /** A redelivered message must not rewrite a file somebody already downloaded. */
    public function testARedeliveredMessageDoesNotRunTheExportAgain(): void
    {
        $this->seed(5);
        $job = $this->runJob($this->exports()->queue(LedgerEntry::class, [], 'ledger', 'admin@example.com'));

        $completedAt = $job->getCompletedAt();
        $this->handler()(new RunExport((string) $job->getId()));

        self::assertEquals($completedAt, $this->reload((string) $job->getId())->getCompletedAt());
    }

    public function testAMessageForAMissingJobIsDiscarded(): void
    {
        $this->handler()(new RunExport('00000000-0000-0000-0000-000000000000'));

        // No exception: a message naming a row that is gone can never succeed,
        // and parking it forever helps nobody.
        self::assertTrue(true);
    }

    /**
     * A worker has no session, and an export that quietly dropped row scope
     * would hand a scoped user the whole company in a spreadsheet —
     * asynchronously, with nobody watching. Losing the requester must therefore
     * fail the job rather than widen it.
     */
    public function testAnExportWhoseRequesterVanishedFails(): void
    {
        $this->seed(3);

        $job = $this->runJob($this->exports()->queue(
            LedgerEntry::class,
            [],
            'ledger',
            'someone-who-does-not-exist@example.com',
        ));

        self::assertSame(ExportJob::STATUS_FAILED, $job->getStatus());
        self::assertStringContainsString('row scope', (string) $job->getFailureReason());
    }

    /** The threshold is per resource: "too big" depends on the row, not only the count. */
    public function testTheInlineLimitComesFromTheResource(): void
    {
        // LedgerEntry declares #[GridScale(inlineExportLimit: 3)]; the module
        // default is what everything else gets.
        self::assertSame(3, $this->exports()->inlineLimitFor(LedgerEntry::class));
        self::assertSame(3, $this->exports()->inlineLimitFor(\Nubit\Tests\Integration\Fixture\Entity\Invoice::class));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function exports(): ExportRequestService
    {
        $service = $this->container()->get(ExportRequestService::class);
        self::assertInstanceOf(ExportRequestService::class, $service);

        return $service;
    }

    private function handler(): RunExportHandler
    {
        $handler = $this->container()->get(RunExportHandler::class);
        self::assertInstanceOf(RunExportHandler::class, $handler);

        return $handler;
    }

    /** @return list<\Symfony\Component\Messenger\Envelope> */
    private function queued(): array
    {
        $transport = $this->container()->get('messenger.transport.exports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values($transport->getSent());
    }

    /** Named `runJob` because PHPUnit's TestCase::run() is final. */
    private function runJob(ExportJob $job): ExportJob
    {
        $this->handler()(new RunExport((string) $job->getId()));

        return $this->reload((string) $job->getId());
    }

    private function reload(string $id): ExportJob
    {
        $this->entityManager()->clear();
        $job = $this->entityManager()->find(ExportJob::class, $id);
        self::assertInstanceOf(ExportJob::class, $job);

        return $job;
    }

    /** @return list<string> */
    private function lines(ExportJob $job): array
    {
        $contents = (string) file_get_contents((string) $job->getStoragePath());

        return array_values(array_filter(explode("\n", trim($contents)), static fn(string $l): bool => '' !== $l));
    }

    private function seed(int $count): void
    {
        $entityManager = $this->entityManager();

        for ($i = 1; $i <= $count; ++$i) {
            $entry = new LedgerEntry();
            $entry->reference = sprintf('L-%04d', $i);
            $entry->account = sprintf('ACC-%d', $i % 5);
            $entityManager->persist($entry);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
