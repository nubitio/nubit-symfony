<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\RateLimit;

use Nubit\Platform\RateLimit\RateLimitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimitResult::class)]
final class RateLimitResultTest extends TestCase
{
    public function testAllPropertiesAreSetCorrectlyWhenAllowed(): void
    {
        $result = new RateLimitResult(true, 100, 99, 0);

        self::assertTrue($result->allowed);
        self::assertSame(100, $result->limit);
        self::assertSame(99, $result->remaining);
        self::assertSame(0, $result->retryAfter);
    }

    public function testAllPropertiesAreSetCorrectlyWhenDenied(): void
    {
        $result = new RateLimitResult(false, 100, 0, 30);

        self::assertFalse($result->allowed);
        self::assertSame(100, $result->limit);
        self::assertSame(0, $result->remaining);
        self::assertSame(30, $result->retryAfter);
    }
}
