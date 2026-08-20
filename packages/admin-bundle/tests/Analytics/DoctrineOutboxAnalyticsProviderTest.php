<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Analytics;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Analytics\DoctrineOutboxAnalyticsProvider;
use Nubit\AdminBundle\Analytics\Entity\AnalyticsOutboxEntry;
use Nubit\Platform\Analytics\AnalyticsPurpose;
use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;
use PHPUnit\Framework\TestCase;

final class DoctrineOutboxAnalyticsProviderTest extends TestCase
{
    public function testPersistsWithoutFlushingSoCallerOwnsTransaction(): void
    {
        $event = new SanitizedAnalyticsEvent(
            'invoice-paid-42',
            'invoice.paid',
            1,
            AnalyticsPurpose::Operational,
            ['channel' => 'web'],
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            42,
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(
                static fn(object $entry): bool => $entry instanceof AnalyticsOutboxEntry && $entry->toEvent() == $event,
            ));
        $entityManager->expects(self::never())->method('flush');

        (new DoctrineOutboxAnalyticsProvider($entityManager))->capture($event);
    }
}
