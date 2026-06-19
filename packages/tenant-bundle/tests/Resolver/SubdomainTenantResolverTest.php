<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Resolver;

use Nubit\TenantBundle\Resolver\SubdomainTenantResolver;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
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
                return 'acme' === $name
                    ? ['id' => 3, 'name' => 'acme', 'primary_domain' => 'acme.example.com']
                    : null;
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
}