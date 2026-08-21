<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Resolver;

use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Nubit\TenantBundle\Resolver\SubdomainTenantResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SubdomainTenantResolverTest extends TestCase
{
    public function testResolvesTenantFromConfiguredBaseDomain(): void
    {
        $registry = new class implements TenantRegistryInterface {
            public function getTenants(): array
            {
                return [];
            }

            public function getTenantByName(string $name): ?array
            {
                return 'acme' === $name ? ['id' => 3, 'name' => 'acme', 'primary_domain' => 'acme.example.com'] : null;
            }

            public function getTenantByDomain(string $domain): ?array
            {
                return null;
            }
        };

        $resolver = new SubdomainTenantResolver($registry, 'example.com');
        $request = Request::create('https://acme.example.com/api/me');

        $tenant = $resolver->resolve($request, null);

        self::assertNotNull($tenant);
        self::assertSame(3, $tenant->id);
        self::assertSame('acme', $tenant->name);
    }

    public function testResolvesAConfiguredCustomDomainWithoutSubdomain(): void
    {
        $registry = $this->createMock(TenantRegistryInterface::class);
        $registry->expects(self::never())->method('getTenantByName');
        $registry
            ->expects(self::once())
            ->method('getTenantByDomain')
            ->with('acme.test')
            ->willReturn(['id' => '8', 'name' => 'acme']);

        $tenant = (new SubdomainTenantResolver($registry))->resolve(Request::create('https://acme.test/api/me'), null);

        self::assertNotNull($tenant);
        self::assertSame(8, $tenant->id);
        self::assertSame('acme.test', $tenant->domain);
    }
}
