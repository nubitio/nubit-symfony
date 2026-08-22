<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Time;

use Nubit\Platform\Time\TimeZoneAwareInterface;
use Nubit\Platform\Time\TimeZoneResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeZoneResolver::class)]
final class TimeZoneResolverTest extends TestCase
{
    public function testTheUserPreferenceWins(): void
    {
        $resolver = new TimeZoneResolver('Europe/Madrid');

        self::assertSame('America/Lima', $resolver->resolveIdentifier(
            new Traveller('America/Lima'),
            new Traveller('Asia/Tokyo'),
        ));
    }

    public function testTheTenantPreferenceIsUsedWhenTheUserHasNone(): void
    {
        $resolver = new TimeZoneResolver('Europe/Madrid');

        self::assertSame('Asia/Tokyo', $resolver->resolveIdentifier(new Traveller(null), new Traveller('Asia/Tokyo')));
    }

    public function testTheConfiguredDefaultIsUsedWhenNobodyStatesOne(): void
    {
        self::assertSame('Europe/Madrid', (new TimeZoneResolver('Europe/Madrid'))->resolveIdentifier());
    }

    public function testUtcIsTheLastResort(): void
    {
        self::assertSame('UTC', (new TimeZoneResolver())->resolveIdentifier());
    }

    public function testAnObjectWithoutAPreferenceIsSimplySkipped(): void
    {
        self::assertSame('UTC', (new TimeZoneResolver())->resolveIdentifier(new \stdClass()));
    }

    /**
     * A stale or mistyped identifier must not take the request down, and must
     * not quietly become the server's own zone either.
     */
    public function testAnUnknownIdentifierFallsThroughToTheNextStep(): void
    {
        $resolver = new TimeZoneResolver('Europe/Madrid');

        self::assertSame('Europe/Madrid', $resolver->resolveIdentifier(new Traveller('Mars/Olympus_Mons')));
    }

    public function testAnUnknownDefaultStillLeavesUtc(): void
    {
        self::assertSame('UTC', (new TimeZoneResolver('Mars/Olympus_Mons'))->resolveIdentifier());
    }

    /**
     * The instant does not move; only the wall-clock reading of it does. An ERP
     * that gets this wrong files a document in the wrong period.
     */
    public function testDisplayConversionKeepsTheInstant(): void
    {
        $resolver = new TimeZoneResolver();
        $stored = new \DateTimeImmutable('2026-03-01 04:30:00', new \DateTimeZone('UTC'));

        $shown = $resolver->toDisplay($stored, new Traveller('America/Lima'));

        self::assertSame($stored->getTimestamp(), $shown->getTimestamp());
        self::assertSame('2026-02-28 23:30:00', $shown->format('Y-m-d H:i:s'));
        self::assertSame('America/Lima', $shown->getTimezone()->getName());
    }
}

final readonly class Traveller implements TimeZoneAwareInterface
{
    public function __construct(
        private ?string $timeZone,
    ) {}

    public function getTimeZone(): ?string
    {
        return $this->timeZone;
    }
}
