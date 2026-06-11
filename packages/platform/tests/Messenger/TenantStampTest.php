<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Messenger;

use Nubit\Platform\Messenger\TenantStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[CoversClass(TenantStamp::class)]
final class TenantStampTest extends TestCase
{
    public function testAllPropertiesAreSetCorrectly(): void
    {
        $stamp = new TenantStamp(42, 'acme', 'acme.example.test', 'req-42');

        self::assertSame(42, $stamp->tenantId);
        self::assertSame('acme', $stamp->tenantName);
        self::assertSame('acme.example.test', $stamp->tenantDomain);
        self::assertSame('req-42', $stamp->requestId);
    }

    public function testNullablePropertiesAcceptNull(): void
    {
        $stamp = new TenantStamp(null, null, null, null);

        self::assertNull($stamp->tenantId);
        self::assertNull($stamp->tenantName);
        self::assertNull($stamp->tenantDomain);
        self::assertNull($stamp->requestId);
    }

    public function testImplementsStampInterface(): void
    {
        $stamp = new TenantStamp(1, 'acme', null, null);

        self::assertInstanceOf(StampInterface::class, $stamp);
    }
}
