<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Resolver;

use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Nubit\TenantBundle\Resolver\HeaderTenantResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HeaderTenantResolverTest extends TestCase
{
    public function testNormalizesTenantNameFromHeader(): void
    {
        $registry = $this->createMock(TenantRegistryInterface::class);
        $registry->expects(self::once())
            ->method('getTenantByName')
            ->with('acme')
            ->willReturn(['id' => 7, 'name' => 'acme']);
        $request = Request::create('/', server: ['HTTP_X_TENANT_ID' => '  acme  ']);

        $tenant = (new HeaderTenantResolver($registry))->resolve($request, null);

        self::assertNotNull($tenant);
        self::assertSame(7, $tenant->id);
    }

    public function testRejectsInvalidRegistryId(): void
    {
        $registry = $this->createStub(TenantRegistryInterface::class);
        $registry->method('getTenantByName')->willReturn(['id' => 'invalid']);
        $request = Request::create('/', server: ['HTTP_X_TENANT_ID' => 'acme']);

        self::assertNull((new HeaderTenantResolver($registry))->resolve($request, null));
    }
}
