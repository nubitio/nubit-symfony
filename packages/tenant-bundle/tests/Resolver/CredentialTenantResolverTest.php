<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Resolver;

use Nubit\Platform\Tenant\Http\TenantCredentialRequestAttribute;
use Nubit\TenantBundle\Resolver\CredentialTenantResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CredentialTenantResolverTest extends TestCase
{
    public function testResolvesTenantFromTheRequestAttribute(): void
    {
        $resolver = new CredentialTenantResolver();
        $request = new Request();
        $request->attributes->set(TenantCredentialRequestAttribute::NAME, 4);

        $tenant = $resolver->resolve($request, null);

        self::assertNotNull($tenant);
        self::assertSame(4, $tenant->id);
    }

    public function testReturnsNullWhenTheAttributeIsAbsent(): void
    {
        $resolver = new CredentialTenantResolver();

        self::assertNull($resolver->resolve(new Request(), null));
    }

    /**
     * A credential minted before tenant-bundle existed, or one deliberately
     * unscoped, carries a null tenant id — that must mean "no opinion", the
     * same as the attribute being absent altogether, never tenant zero.
     */
    public function testReturnsNullWhenTheCredentialHasNoTenant(): void
    {
        $resolver = new CredentialTenantResolver();
        $request = new Request();
        $request->attributes->set(TenantCredentialRequestAttribute::NAME, null);

        self::assertNull($resolver->resolve($request, null));
    }
}
