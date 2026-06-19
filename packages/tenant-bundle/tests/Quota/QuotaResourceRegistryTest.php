<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Quota;

use Nubit\TenantBundle\Attribute\QuotaResource;
use Nubit\TenantBundle\Quota\QuotaResourceRegistry;
use PHPUnit\Framework\TestCase;

final class QuotaResourceRegistryTest extends TestCase
{
    public function testResolvesAttributedEntity(): void
    {
        $registry = new QuotaResourceRegistry();

        self::assertSame('team_users', $registry->resolve(new QuotaEntityStub()));
    }

    public function testReturnsNullForUnattributedEntity(): void
    {
        $registry = new QuotaResourceRegistry();

        self::assertNull($registry->resolve(new \stdClass()));
    }
}

#[QuotaResource('team_users')]
final class QuotaEntityStub
{
}