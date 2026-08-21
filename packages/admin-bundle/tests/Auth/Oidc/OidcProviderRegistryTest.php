<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth\Oidc;

use Nubit\AdminBundle\Auth\Oidc\OidcProviderRegistry;
use PHPUnit\Framework\TestCase;

final class OidcProviderRegistryTest extends TestCase
{
    public function testBuildsProviderConfigsKeyedByName(): void
    {
        $registry = new OidcProviderRegistry([
            'okta' => [
                'issuer' => 'https://acme.okta.com',
                'client_id' => 'abc',
                'client_secret' => 'secret',
                'scopes' => ['openid', 'email'],
                'redirect_uri' => 'https://app.example.com/api/auth/oidc/okta/callback',
                'post_login_redirect_uri' => 'https://app.example.com/',
            ],
        ]);

        $okta = $registry->get('okta');

        static::assertNotNull($okta);
        static::assertSame('okta', $okta->name);
        static::assertSame('https://acme.okta.com', $okta->issuer);
        static::assertSame(['openid', 'email'], $okta->scopes);
    }

    public function testReturnsNullForAnUnknownProvider(): void
    {
        $registry = new OidcProviderRegistry([]);

        static::assertNull($registry->get('does-not-exist'));
    }

    public function testAllReturnsEveryConfiguredProvider(): void
    {
        $registry = new OidcProviderRegistry([
            'okta' => [
                'issuer' => 'a',
                'client_id' => 'b',
                'client_secret' => 'c',
                'scopes' => [],
                'redirect_uri' => 'd',
                'post_login_redirect_uri' => 'e',
            ],
            'azure' => [
                'issuer' => 'f',
                'client_id' => 'g',
                'client_secret' => 'h',
                'scopes' => [],
                'redirect_uri' => 'i',
                'post_login_redirect_uri' => 'j',
            ],
        ]);

        static::assertCount(2, $registry->all());
    }
}
