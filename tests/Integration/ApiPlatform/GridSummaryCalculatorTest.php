<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\ApiPlatform;

use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Tests\Integration\Fixture\Entity\Invoice;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GridSummaryCalculator` used to build its aggregate query without applying
 * any of the request's grid filters, so a filtered grid's footer total was
 * always computed over the whole table — visibly wrong next to the filtered
 * rows sitting above it. These tests pin the total to the filtered rows.
 */
#[CoversNothing]
final class GridSummaryCalculatorTest extends IntegrationTestCase
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

    public function testUnfilteredSummaryTotalsEveryRow(): void
    {
        // 100.00 + 1000.00 + 9.50
        self::assertSame('1109.50', $this->summaryTotal($this->grid()));
    }

    /**
     * The bug this guards against: the summary used to ignore `filter`
     * entirely, so this would have returned the unfiltered 1109.50 while the
     * grid itself showed only the two Acme rows.
     */
    public function testFilteredSummaryTotalsOnlyTheFilteredRows(): void
    {
        $response = $this->grid([
            'filter' => json_encode(['customer', '=', 'Acme'], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(['A-001', 'A-002'], $this->numbers($response));
        // 100.00 + 1000.00, not 1109.50.
        self::assertSame('1100.00', $this->summaryTotal($response));
    }

    public function testGlobalSearchAlsoNarrowsTheSummary(): void
    {
        $response = $this->grid([
            'searchValue' => 'Globex',
            'searchExpr' => ['customer'],
        ]);

        self::assertSame(['B-001'], $this->numbers($response));
        self::assertSame('9.50', $this->summaryTotal($response));
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

    private function summaryTotal(Response $response): string
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $header = $response->headers->get('X-Grid-Summary');
        self::assertNotNull($header, 'Response carries no X-Grid-Summary header.');

        /** @var array<string, mixed> $summary */
        $summary = json_decode($header, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('total', $summary);
        self::assertIsString($summary['total']);

        return $summary['total'];
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

        sort($numbers);

        return $numbers;
    }

    private function seed(): void
    {
        $entityManager = $this->entityManager();

        $rows = [
            ['A-001', 'Acme',   '100.00'],
            ['A-002', 'Acme',   '1000.00'],
            ['B-001', 'Globex', '9.50'],
        ];

        foreach ($rows as [$number, $customer, $total]) {
            $invoice = new Invoice();
            $invoice->number = $number;
            $invoice->customer = $customer;
            $invoice->total = $total;
            $invoice->issuedAt = new \DateTimeImmutable('2026-01-01');
            $entityManager->persist($invoice);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
