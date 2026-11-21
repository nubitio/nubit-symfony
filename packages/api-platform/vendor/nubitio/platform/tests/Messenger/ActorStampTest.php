<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Messenger;

use Nubit\Platform\Messenger\ActorStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[CoversClass(ActorStamp::class)]
final class ActorStampTest extends TestCase
{
    public function testAllPropertiesAreSetCorrectly(): void
    {
        $stamp = new ActorStamp('user:42', 'http', 'invoice:create');

        self::assertSame('user:42', $stamp->actorIdentifier);
        self::assertSame('http', $stamp->channel);
        self::assertSame('invoice:create', $stamp->commandName);
    }

    public function testCommandNameDefaultsToNull(): void
    {
        $stamp = new ActorStamp('user:42', 'http');

        self::assertNull($stamp->commandName);
    }

    public function testNullablePropertiesAcceptNull(): void
    {
        $stamp = new ActorStamp(null, null, null);

        self::assertNull($stamp->actorIdentifier);
        self::assertNull($stamp->channel);
        self::assertNull($stamp->commandName);
    }

    public function testImplementsStampInterface(): void
    {
        $stamp = new ActorStamp('user:1', 'cli');

        self::assertInstanceOf(StampInterface::class, $stamp);
    }
}
