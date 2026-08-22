<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Money;

use Doctrine\DBAL\Connection;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Tests\Integration\Fixture\Entity\Payment;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Timestamps mean one thing in the database, whatever the server's locale is.
 *
 * This is the half of the timezone policy that cannot be unit-tested: the bug
 * lives in what Doctrine hands to PostgreSQL and what it parses back, and both
 * ends only exist when a real connection is in play. The server timezone is
 * moved off UTC on purpose here — with the stock Doctrine type that alone is
 * enough to shift every stored value.
 */
#[CoversNothing]
final class UtcStorageTest extends IntegrationTestCase
{
    private string $originalTimeZone = 'UTC';

    protected function setUp(): void
    {
        $this->originalTimeZone = date_default_timezone_get();
        date_default_timezone_set('America/Lima');

        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                    'time' => ['default_timezone' => 'America/Lima'],
                ],
            ],
            self::fixtureMapping(),
        );

        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimeZone);

        parent::tearDown();
    }

    public function testAnInstantIsStoredInUtcRegardlessOfTheServerZone(): void
    {
        $entityManager = $this->entityManager();

        $payment = new Payment();
        $payment->reference = 'P-TZ';
        // 04:30 UTC is the previous day in Lima; the column has to hold the UTC
        // reading, not the local one.
        $payment->occurredAt = new \DateTimeImmutable('2026-03-01 04:30:00', new \DateTimeZone('UTC'));
        $entityManager->persist($payment);
        $entityManager->flush();
        $entityManager->clear();

        $connection = $entityManager->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        $stored = $connection->executeQuery('SELECT occurred_at FROM fixture_payment')->fetchOne();

        self::assertIsString($stored);
        self::assertStringStartsWith('2026-03-01 04:30:00', $stored);
    }

    /** A value handed over in another zone is converted, not written verbatim. */
    public function testALocalInstantIsConvertedOnTheWayIn(): void
    {
        $entityManager = $this->entityManager();

        $payment = new Payment();
        $payment->reference = 'P-LOCAL';
        $payment->occurredAt = new \DateTimeImmutable('2026-02-28 23:30:00', new \DateTimeZone('America/Lima'));
        $entityManager->persist($payment);
        $entityManager->flush();
        $entityManager->clear();

        $connection = $entityManager->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $stored = $connection->executeQuery('SELECT occurred_at FROM fixture_payment')->fetchOne();

        self::assertIsString($stored);
        self::assertStringStartsWith('2026-03-01 04:30:00', $stored);
    }

    /**
     * Reading is the half that is easy to forget. Doctrine's own type parses the
     * stored string in PHP's default zone, so on this deliberately non-UTC
     * server it would come back five hours out.
     */
    public function testAnInstantIsReadBackAsUtc(): void
    {
        $entityManager = $this->entityManager();

        $payment = new Payment();
        $payment->reference = 'P-TZ';
        $payment->occurredAt = new \DateTimeImmutable('2026-03-01 04:30:00', new \DateTimeZone('UTC'));
        $entityManager->persist($payment);
        $entityManager->flush();
        $id = $payment->getId();
        $entityManager->clear();

        $reloaded = $entityManager->find(Payment::class, $id);
        self::assertInstanceOf(Payment::class, $reloaded);
        self::assertNotNull($reloaded->occurredAt);

        self::assertSame('UTC', $reloaded->occurredAt->getTimezone()->getName());
        self::assertSame('2026-03-01 04:30:00', $reloaded->occurredAt->format('Y-m-d H:i:s'));
    }

    /** The session tells the frontend which zone to render in. */
    public function testTheSessionProfileReportsTheDisplayTimeZone(): void
    {
        $resolver = $this->container()->get(\Nubit\Platform\Time\TimeZoneResolver::class);
        self::assertInstanceOf(\Nubit\Platform\Time\TimeZoneResolver::class, $resolver);

        self::assertSame('America/Lima', $resolver->resolveIdentifier());
    }
}
