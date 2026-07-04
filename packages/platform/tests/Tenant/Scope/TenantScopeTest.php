<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Tenant\Scope;

use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Scope\TenantScope;
use PHPUnit\Framework\TestCase;

final class TenantScopeTest extends TestCase
{
    public function testBuildsScopedCacheKeysAndPathsWhenTenantIsActive(): void
    {
        $context = new TenantContext();
        $context->setTenant(1, 'acme/central', null, null);
        $scope = new TenantScope($context);

        self::assertSame('t.acme_central.dashboard', $scope->cacheKey('dashboard'));
        self::assertSame('acme_central/documents/invoice.xml', $scope->path('/documents/invoice.xml'));
        self::assertSame('tenant_rl.acme_central.123', $scope->rateLimitKey('acme/central', '123'));
    }

    public function testLeavesCacheKeysAndPathsUnscopedWhenTenantIsMissing(): void
    {
        $scope = new TenantScope(new TenantContext());

        self::assertSame('dashboard', $scope->cacheKey('dashboard'));
        self::assertSame('documents/invoice.xml', $scope->path('/documents/invoice.xml'));
    }

    public function testSanitizeKeepsStableSafeCharacters(): void
    {
        self::assertSame('tenant-a.prod_01', TenantScope::sanitize('tenant-a.prod_01'));
        self::assertSame('tenant_colon', TenantScope::sanitize('tenant:colon'));
        self::assertSame('unknown', TenantScope::sanitize('   '));
    }
}
