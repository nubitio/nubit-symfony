<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Quota;

use Nubit\Platform\Exception\ServiceException;
use Nubit\TenantBundle\Contract\QuotaUsageProviderInterface;
use Nubit\TenantBundle\Quota\CompositeQuotaUsageProvider;
use PHPUnit\Framework\TestCase;

final class CompositeQuotaUsageProviderTest extends TestCase
{
    public function testDelegatesToSupportingProvider(): void
    {
        $primary = $this->createMock(QuotaUsageProviderInterface::class);
        $primary->method('supports')->willReturnMap([
            ['team_users', true],
            ['orders', false],
        ]);
        $primary->method('count')->with('team_users')->willReturn(4);

        $secondary = $this->createMock(QuotaUsageProviderInterface::class);
        $secondary->method('supports')->willReturn(false);

        $composite = new CompositeQuotaUsageProvider([$secondary, $primary]);

        self::assertTrue($composite->supports('team_users'));
        self::assertSame(4, $composite->count('team_users'));
    }

    public function testThrowsWhenNoProviderRegistered(): void
    {
        $composite = new CompositeQuotaUsageProvider([]);

        self::assertFalse($composite->supports('team_users'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('No quota usage provider registered for resource "team_users".');

        $composite->count('team_users');
    }
}