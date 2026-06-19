<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Resolver;

use Nubit\TenantBundle\Resolver\CompositeTenantResolver;
use Nubit\TenantBundle\Resolver\ResolvedTenant;
use Nubit\TenantBundle\Resolver\TenantResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

final class CompositeTenantResolverTest extends TestCase
{
    public function testReturnsFirstMatchingResolver(): void
    {
        $resolver = new CompositeTenantResolver([
            new class implements TenantResolverInterface {
                public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
                {
                    return null;
                }
            },
            new class implements TenantResolverInterface {
                public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
                {
                    return new ResolvedTenant(42, 'acme');
                }
            },
        ]);

        $tenant = $resolver->resolve(new Request(), null);

        self::assertNotNull($tenant);
        self::assertSame(42, $tenant->id);
        self::assertSame('acme', $tenant->name);
    }
}