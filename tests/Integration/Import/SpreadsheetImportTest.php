<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Import;

use Nubit\AdminBundle\Import\Entity\ImportSession;
use Nubit\AdminBundle\Import\ImportService;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Tests\Integration\Fixture\Entity\ImportedProduct;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Loading a spreadsheet, and above all not loading it.
 *
 * The dry run is the feature. An import that validated and wrote in one step
 * would leave the user choosing between trusting a file blindly and not
 * importing at all — and "undo the import" stops existing the moment foreign
 * keys have been written. Every test here is really about what the database
 * looks like *before* anyone confirms.
 */
#[CoversNothing]
final class SpreadsheetImportTest extends IntegrationTestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/nubit-import-test-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->workspace);

        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                    'imports' => ['enabled' => true, 'directory' => $this->workspace . '/store'],
                ],
            ],
            self::fixtureMapping(),
        );

        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ('' !== $this->workspace && is_dir($this->workspace)) {
            (new Filesystem())->remove($this->workspace);
        }
    }

    public function testUploadingAnalysesWithoutWritingAnything(): void
    {
        $session = $this->start(<<<'CSV'
            sku,name,price,active,launchedAt,stock
            SKU-1,Espresso Machine,450.00,yes,2026-01-15,12
            SKU-2,Grinder,199.90,no,2026-02-01,4
            CSV);

        self::assertSame(ImportSession::STATUS_ANALYZED, $session->getStatus());

        $report = $session->getReport();
        self::assertSame(2, $report['rows']);
        self::assertSame(2, $report['inserts']);
        self::assertSame(0, $report['updates']);
        self::assertSame(0, $report['invalid']);
        self::assertFalse($report['applied']);

        self::assertSame(0, $this->countProducts(), 'The dry run wrote to the database.');
    }

    public function testConfirmingApplies(): void
    {
        $session = $this->start(<<<'CSV'
            sku,name,price,active,launchedAt,stock
            SKU-1,Espresso Machine,450.00,yes,2026-01-15,12
            SKU-2,Grinder,199.90,no,2026-02-01,4
            CSV);

        $this->imports()->confirm($session);

        self::assertSame(2, $this->countProducts());

        $product = $this->product('SKU-1');
        self::assertSame('Espresso Machine', $product->name);
        self::assertSame('450.00', $product->getPrice()?->toDecimalString());
        self::assertTrue($product->active);
        self::assertSame('2026-01-15', $product->launchedAt?->format('Y-m-d'));
        self::assertSame(12, $product->stock);
    }

    /**
     * The natural key is what makes a corrected file safe to re-upload. Without
     * it, fixing one row and uploading again duplicates every row that was
     * already fine.
     */
    public function testReimportingUpdatesRatherThanDuplicates(): void
    {
        $this->imports()->confirm($this->start(<<<'CSV'
            sku,name,price
            SKU-1,Espresso Machine,450.00
            CSV));

        $second = $this->start(<<<'CSV'
            sku,name,price
            SKU-1,Espresso Machine Pro,499.00
            CSV);

        self::assertSame(1, $second->getReport()['updates']);
        self::assertSame(0, $second->getReport()['inserts']);

        $this->imports()->confirm($second);

        self::assertSame(1, $this->countProducts());
        self::assertSame('Espresso Machine Pro', $this->product('SKU-1')->name);
    }

    /** Errors name the line the user can see in their spreadsheet, and the column. */
    public function testInvalidRowsAreReportedByLine(): void
    {
        $session = $this->start(<<<'CSV'
            sku,name,price,stock
            SKU-1,Espresso Machine,450.00,12
            SKU-2,Grinder,not-a-number,4
            SKU-3,X,199.90,not-an-integer
            CSV);

        $report = $session->getReport();
        self::assertSame(3, $report['rows']);
        self::assertSame(2, $report['invalid']);
        self::assertSame(1, $report['inserts']);

        $byLine = [];
        foreach (self::rowList($report, 'errors') as $error) {
            $byLine[(int) $error['line']][] = $error;
        }

        self::assertArrayHasKey(3, $byLine);
        self::assertSame('price', $byLine[3][0]['field']);
        self::assertArrayHasKey(4, $byLine);
    }

    /** A partial import is the worst outcome available; applying is all or nothing. */
    public function testApplyingAFileWithInvalidRowsIsRefusedAndWritesNothing(): void
    {
        $session = $this->start(<<<'CSV'
            sku,name,price
            SKU-1,Espresso Machine,450.00
            SKU-2,Grinder,not-a-number
            CSV);

        try {
            $this->imports()->confirm($session);
            self::fail('An import with invalid rows was applied.');
        } catch (\Nubit\AdminBundle\Import\Exception\ImportException $exception) {
            self::assertStringContainsString('invalid', $exception->getMessage());
        }

        self::assertSame(0, $this->countProducts(), 'A refused import left rows behind.');
    }

    /** Validation constraints on the entity are part of the dry run, not a surprise at apply time. */
    public function testEntityConstraintsAreEnforced(): void
    {
        $session = $this->start(<<<'CSV'
            sku,name
            SKU-1,A
            CSV);

        self::assertSame(1, $session->getReport()['invalid']);
        self::assertSame('name', self::rowList($session->getReport(), 'errors')[0]['field']);
    }

    public function testMissingRequiredValuesAreReported(): void
    {
        $session = $this->start(<<<'CSV'
            sku,name
            SKU-1,
            CSV);

        self::assertSame(1, $session->getReport()['invalid']);
    }

    /** Excel writes semicolons wherever the comma is the decimal separator. */
    public function testSemicolonDelimitedFilesAreRead(): void
    {
        $session = $this->start("sku;name;price\nSKU-1;Espresso Machine;450.00\n");

        self::assertSame(1, $session->getReport()['rows']);
        self::assertSame(0, $session->getReport()['invalid']);
    }

    /** Headers are matched on meaning, not on exact spelling. */
    public function testHeadersAreMatchedIgnoringCaseAndSpacing(): void
    {
        $session = $this->start(<<<'CSV'
            SKU,Name,Launched At
            SKU-1,Espresso Machine,2026-01-15
            CSV);

        self::assertArrayHasKey('launchedAt', $session->getMapping());
        self::assertSame(0, $session->getReport()['invalid']);
    }

    /** A column nobody mapped is reported rather than silently dropped. */
    public function testUnmappedColumnsAreReported(): void
    {
        $session = $this->start(<<<'CSV'
            sku,name,supplier_reference
            SKU-1,Espresso Machine,ACME-1
            CSV);

        $unmapped = $session->getReport()['unmapped'];
        self::assertIsArray($unmapped);
        self::assertContains('price', $unmapped);
    }

    /**
     * "1,234" means two different numbers to two readers, and reading it wrong
     * moves an amount by a factor of a thousand. It is refused rather than
     * guessed at.
     */
    public function testAnAmbiguousNumberIsRefusedRatherThanGuessed(): void
    {
        $session = $this->start("sku;name;price\nSKU-1;Espresso Machine;1,234\n");

        self::assertSame(1, $session->getReport()['invalid']);
        self::assertStringContainsString(
            'could mean',
            (string) self::rowList($session->getReport(), 'errors')[0]['message'],
        );
    }

    public function testDeclaringTheNumberFormatResolvesTheAmbiguity(): void
    {
        $session = $this->start("sku;name;price\nSKU-1;Espresso Machine;1,234\n", numberFormat: 'comma');
        $this->imports()->confirm($session);

        self::assertSame('1.23', $this->product('SKU-1')->getPrice()?->toDecimalString());
    }

    /** A date that rolls over is a date the user did not write. */
    public function testAnImpossibleDateIsRejected(): void
    {
        $session = $this->start(<<<'CSV'
            sku,name,launchedAt
            SKU-1,Espresso Machine,31/02/2026
            CSV);

        self::assertSame(1, $session->getReport()['invalid']);
        self::assertSame('launchedAt', self::rowList($session->getReport(), 'errors')[0]['field']);
    }

    public function testApplyingTwiceIsRefused(): void
    {
        $session = $this->start("sku,name\nSKU-1,Espresso Machine\n");
        $this->imports()->confirm($session);

        $this->expectException(\Nubit\AdminBundle\Import\Exception\ImportException::class);
        $this->imports()->confirm($session);
    }

    /** Applying without a dry run is the one thing the module exists to prevent. */
    public function testApplyingAnUnanalysedSessionIsRefused(): void
    {
        $session = $this->start("sku,name\nSKU-1,Espresso Machine\n");
        $session->setStatus(ImportSession::STATUS_UPLOADED);
        $this->entityManager()->flush();

        $this->expectException(\Nubit\AdminBundle\Import\Exception\ImportException::class);
        $this->imports()->confirm($session);
    }

    /** Batching must not change the outcome — only how often it flushes. */
    public function testABatchLargerThanOneFlushStillImportsEveryRow(): void
    {
        $rows = "sku,name\n";
        for ($i = 1; $i <= 7; ++$i) {
            $rows .= sprintf("SKU-%d,Product %d\n", $i, $i);
        }

        $this->imports()->confirm($this->start($rows));

        self::assertSame(7, $this->countProducts());
    }

    private function start(string $csv, string $numberFormat = 'auto'): ImportSession
    {
        $path = $this->workspace . '/upload-' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, $csv);

        return $this->imports()->start(
            'imported-products',
            new UploadedFile($path, 'products.csv', 'text/csv', test: true),
            'admin@example.com',
            [],
            $numberFormat,
        );
    }

    private function imports(): ImportService
    {
        $service = $this->container()->get(ImportService::class);
        self::assertInstanceOf(ImportService::class, $service);

        return $service;
    }

    private function countProducts(): int
    {
        $this->entityManager()->clear();

        return count($this->entityManager()->getRepository(ImportedProduct::class)->findAll());
    }

    private function product(string $sku): ImportedProduct
    {
        $this->entityManager()->clear();
        $product = $this->entityManager()->getRepository(ImportedProduct::class)->findOneBy(['sku' => $sku]);
        self::assertInstanceOf(ImportedProduct::class, $product);

        return $product;
    }
}
