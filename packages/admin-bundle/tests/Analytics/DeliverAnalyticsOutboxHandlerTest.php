<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Analytics;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Analytics\DeliverAnalyticsOutboxHandler;
use Nubit\AdminBundle\Analytics\Entity\AnalyticsOutboxEntry;
use Nubit\AdminBundle\Analytics\Message\DeliverAnalyticsOutbox;
use Nubit\Platform\Analytics\AnalyticsPurpose;
use Nubit\Platform\Analytics\Contract\AnalyticsDeliveryProviderInterface;
use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeliverAnalyticsOutboxHandlerTest extends TestCase
{
    public function testDeliversLockedEntryAndMarksItOnce(): void
    {
        $entry = $this->entry();
        $provider = new RecordingDeliveryProvider();
        $entityManager = $this->entityManager($entry);

        (new DeliverAnalyticsOutboxHandler($entityManager, $provider))(new DeliverAnalyticsOutbox(42));

        self::assertTrue($entry->isDelivered());
        self::assertSame('invoice-paid-42', $provider->event?->id);
    }

    public function testPersistsFailureScheduleBeforeRethrowing(): void
    {
        $entry = $this->entry();
        $entityManager = $this->entityManager($entry);
        $provider = new FailingDeliveryProvider();

        try {
            (new DeliverAnalyticsOutboxHandler($entityManager, $provider, 60))(new DeliverAnalyticsOutbox(42));
            self::fail('Expected delivery exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('provider secret must not be persisted', $exception->getMessage());
        }

        self::assertSame(1, $entry->getAttempts());
        self::assertSame(RuntimeException::class, $entry->getLastErrorType());
    }

    private function entry(): AnalyticsOutboxEntry
    {
        return new AnalyticsOutboxEntry(
            new SanitizedAnalyticsEvent(
                'invoice-paid-42',
                'invoice.paid',
                1,
                AnalyticsPurpose::Operational,
                ['channel' => 'web'],
                new DateTimeImmutable('-1 minute'),
                42,
            ),
            new DateTimeImmutable('-1 minute'),
        );
    }

    private function entityManager(AnalyticsOutboxEntry $entry): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static function (callable $operation) use ($entityManager): void {
                $operation($entityManager);
            });
        $entityManager
            ->expects(self::once())
            ->method('find')
            ->with(AnalyticsOutboxEntry::class, 42, LockMode::PESSIMISTIC_WRITE)
            ->willReturn($entry);
        $entityManager->expects(self::once())->method('flush');

        return $entityManager;
    }
}

/** @internal */
final class RecordingDeliveryProvider implements AnalyticsDeliveryProviderInterface
{
    public ?SanitizedAnalyticsEvent $event = null;

    public function deliver(SanitizedAnalyticsEvent $event): void
    {
        $this->event = $event;
    }
}

/** @internal */
final readonly class FailingDeliveryProvider implements AnalyticsDeliveryProviderInterface
{
    public function deliver(SanitizedAnalyticsEvent $event): void
    {
        throw new RuntimeException('provider secret must not be persisted');
    }
}
