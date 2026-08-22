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
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Exporting a real spreadsheet, at a size that used to be the problem.
 *
 * PhpSpreadsheet — which the inline export uses for its styling, totals and
 * validation — builds the whole workbook in memory before writing a byte, so a
 * large XLSX is an out-of-memory error rather than a slow one. OpenSpout appends
 * each row to the sheet as it arrives.
 *
 * The claim "memory stays flat" is worth measuring rather than asserting in a
 * comment, so this suite does.
 */
#[CoversNothing]
final class XlsxExportTest extends IntegrationTestCase
{
    /**
     * Enough rows that a buffering writer would be visibly worse, and few enough
     * that the suite still runs on every push. The memory assertion is what
     * carries the meaning; the row count only has to be past the point where a
     * per-row cost would show up.
     */
    private const int LARGE = 50_000;

    private string $workspace = '';

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/nubit-xlsx-test-' . bin2hex(random_bytes(4));

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
                        'queued_format' => 'xlsx',
                    ],
                ],
            ],
            self::fixtureMapping(),
            [
                'providers' => [
                    'app_users' => ['entity' => ['class' => TestUser::class, 'property' => 'email']],
                ],
                'firewalls' => ['main' => ['security' => false]],
            ],
        );

        $this->resetSchema();
        $this->seedUser();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ('' !== $this->workspace && is_dir($this->workspace)) {
            (new Filesystem())->remove($this->workspace);
        }
    }

    public function testTheFileIsARealSpreadsheet(): void
    {
        $this->seedRows(5);

        $job = $this->export();

        self::assertSame(ExportJob::STATUS_READY, $job->getStatus(), (string) $job->getFailureReason());
        self::assertStringEndsWith('.xlsx', (string) $job->getStoragePath());

        $rows = $this->readSheet((string) $job->getStoragePath());

        // Header plus every row, read back by a spreadsheet reader rather than
        // by looking at bytes: a file that only this code can parse is not a
        // spreadsheet.
        self::assertCount(6, $rows);
        self::assertContains('reference', $rows[0]);
        self::assertContains('L-0001', $rows[1]);
    }

    public function testEveryRowSurvivesTheRoundTrip(): void
    {
        $this->seedRows(1_200);

        $job = $this->export();

        self::assertSame(1_200, $job->getRowCount());
        self::assertCount(1_201, $this->readSheet((string) $job->getStoragePath()));
    }

    /**
     * The claim the whole design rests on.
     *
     * A writer that buffers grows with the data; this one must not. Measured at
     * 4 MB of peak growth for 50,000 rows when this was written — the bound is
     * left far above that on purpose, because the test is checking for an order
     * of magnitude rather than benchmarking an allocator. The same rows through
     * PhpSpreadsheet would be hundreds of megabytes and would fail by a wide
     * margin, which is the whole reason this writer exists.
     */
    public function testMemoryStaysFlatAcrossFiftyThousandRows(): void
    {
        $this->seedRows(self::LARGE);

        $before = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);

        $job = $this->export();

        self::assertSame(ExportJob::STATUS_READY, $job->getStatus(), (string) $job->getFailureReason());
        self::assertSame(self::LARGE, $job->getRowCount());

        $grew = memory_get_peak_usage(true) - $peakBefore;
        $retained = memory_get_usage(true) - $before;

        self::assertLessThan(
            64 * 1024 * 1024,
            $grew,
            sprintf('Writing %d rows grew peak memory by %d bytes.', self::LARGE, $grew),
        );
        self::assertLessThan(
            32 * 1024 * 1024,
            $retained,
            sprintf('Writing %d rows retained %d bytes afterwards.', self::LARGE, $retained),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function export(): ExportJob
    {
        $job = $this->exports()->queue(LedgerEntry::class, [], 'ledger', 'admin@example.com');

        $handler = $this->container()->get(RunExportHandler::class);
        self::assertInstanceOf(RunExportHandler::class, $handler);
        $handler(new RunExport((string) $job->getId()));

        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(ExportJob::class, (string) $job->getId());
        self::assertInstanceOf(ExportJob::class, $reloaded);

        return $reloaded;
    }

    private function exports(): ExportRequestService
    {
        $service = $this->container()->get(ExportRequestService::class);
        self::assertInstanceOf(ExportRequestService::class, $service);

        return $service;
    }

    /** @return list<list<string>> */
    private function readSheet(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(strval(...), $row->toArray());
            }
            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * Seeded in SQL, not through Doctrine.
     *
     * Fifty thousand entities through the unit of work would measure Doctrine's
     * hydration, not the writer's memory — and would take longer than the export
     * being tested.
     */
    private function seedRows(int $count): void
    {
        $this
            ->entityManager()
            ->getConnection()
            ->executeStatement(<<<'SQL'
                INSERT INTO fixture_ledger_entry (id, reference, account)
                SELECT i, 'L-' || lpad(i::text, 4, '0'), 'ACC-' || (i % 5)
                FROM generate_series(1, ?) AS i
                SQL, [$count]);
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
}
