<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Analytics;

use DateTimeImmutable;
use Nubit\AdminBundle\Analytics\Entity\AnalyticsOutboxEntry;
use Nubit\Platform\Analytics\AnalyticsPurpose;
use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnalyticsOutboxEntryTest extends TestCase
{
    public function testRoundTripsSanitizedEventAndSchedulesBoundedRetry(): void
    {
        $now = new DateTimeImmutable('2026-08-19T12:00:00+00:00');
        $event = new SanitizedAnalyticsEvent(
            'invoice-paid-42',
            'invoice.paid',
            2,
            AnalyticsPurpose::Operational,
            ['channel' => 'web'],
            $now->modify('-1 minute'),
            42,
        );
        $entry = new AnalyticsOutboxEntry($event, $now);

        self::assertEquals($event, $entry->toEvent());
        self::assertTrue($entry->isDue($now));

        $entry->markFailed(new RuntimeException('token=secret card=4111111111111111'), $now, 60);
        self::assertSame(1, $entry->getAttempts());
        self::assertSame(RuntimeException::class, $entry->getLastErrorType());
        self::assertEquals($now->modify('+2 seconds'), $entry->getNextAttemptAt());
        self::assertFalse($entry->isDue($now));

        $entry->markDelivered($now->modify('+3 seconds'));
        self::assertTrue($entry->isDelivered());
        self::assertNull($entry->getLastErrorType());
    }
}
