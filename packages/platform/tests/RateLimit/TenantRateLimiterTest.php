<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\RateLimit;

use Nubit\Platform\RateLimit\RateLimitResult;
use Nubit\Platform\RateLimit\TenantRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TenantRateLimiterTest extends TestCase
{
    public function testRateLimitResultExposesValueObjectState(): void
    {
        $result = new RateLimitResult(allowed: false, limit: 10, remaining: 0, retryAfter: 30);

        self::assertFalse($result->allowed);
        self::assertSame(10, $result->limit);
        self::assertSame(0, $result->remaining);
        self::assertSame(30, $result->retryAfter);
    }

    public function testTenantRateLimiterAllowsUntilLimitThenBlocks(): void
    {
        $limiter = new TenantRateLimiter(new ArrayAdapter(), '2', '60');

        $first = $limiter->check('acme');
        $second = $limiter->check('acme');
        $third = $limiter->check('acme');

        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertTrue($second->allowed);
        self::assertSame(0, $second->remaining);
        self::assertFalse($third->allowed);
        self::assertSame(0, $third->remaining);
        self::assertGreaterThanOrEqual(1, $third->retryAfter);
        self::assertLessThanOrEqual(60, $third->retryAfter);
    }

    public function testTenantRateLimiterSanitizesTenantNamesIntoDistinctCacheKeys(): void
    {
        $cache = new ArrayAdapter();
        $limiter = new TenantRateLimiter($cache, '1', '60');

        self::assertTrue($limiter->check('tenant/slash')->allowed);
        self::assertFalse($limiter->check('tenant/slash')->allowed);
        self::assertTrue($limiter->check('tenant:colon')->allowed);
    }

    public function testTenantRateLimiterCanBeDisabledWithNonPositiveLimit(): void
    {
        $limiter = new TenantRateLimiter(new ArrayAdapter(), '0', '60');

        $result = $limiter->check('acme');

        self::assertTrue($result->allowed);
        self::assertSame(0, $result->limit);
        self::assertSame(0, $result->remaining);
        self::assertSame(0, $result->retryAfter);
    }
}
