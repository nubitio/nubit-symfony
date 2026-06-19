<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Resolver;

use Firebase\JWT\JWT;
use Nubit\TenantBundle\Resolver\JwtClaimTenantResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class JwtClaimTenantResolverTest extends TestCase
{
    public function testReadsTenantClaimFromBearerToken(): void
    {
        $secret = 'test-secret-test-secret-test-secret!!';
        $token = JWT::encode([
            'tenantId' => 99,
            'tenantName' => 'acme',
        ], $secret, 'HS256');

        $resolver = new JwtClaimTenantResolver($secret);
        $request = Request::create('/', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        $tenant = $resolver->resolve($request, null);

        self::assertNotNull($tenant);
        self::assertSame(99, $tenant->id);
        self::assertSame('acme', $tenant->name);
    }
}