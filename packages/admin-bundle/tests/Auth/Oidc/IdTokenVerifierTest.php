<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth\Oidc;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Nubit\AdminBundle\Auth\Oidc\IdTokenVerifier;
use Nubit\AdminBundle\Auth\Oidc\JwksKeyProviderInterface;
use Nubit\AdminBundle\Auth\Oidc\OidcAuthenticationException;
use Nubit\AdminBundle\Auth\Oidc\OidcDiscoveryDocument;
use Nubit\AdminBundle\Auth\Oidc\OidcProviderConfig;
use PHPUnit\Framework\TestCase;

/**
 * Exercises real signature verification (via firebase/php-jwt, HS256 for
 * test simplicity — the algorithm is irrelevant to what's under test: iss/
 * aud/nonce enforcement layered on top of JWT::decode()) rather than mocking
 * it away, since this is the single most security-critical piece of the
 * whole OIDC flow.
 */
final class IdTokenVerifierTest extends TestCase
{
    private const string SECRET = 'a-test-hmac-secret-at-least-32-bytes-long';
    private const string ISSUER = 'https://idp.example.com';
    private const string CLIENT_ID = 'test-client-id';

    private function provider(): OidcProviderConfig
    {
        return new OidcProviderConfig(
            name: 'test',
            issuer: self::ISSUER,
            clientId: self::CLIENT_ID,
            clientSecret: 'unused-here',
            scopes: ['openid'],
            redirectUri: 'https://app.example.com/callback',
            postLoginRedirectUri: 'https://app.example.com/',
        );
    }

    private function discovery(): OidcDiscoveryDocument
    {
        return new OidcDiscoveryDocument(
            issuer: self::ISSUER,
            authorizationEndpoint: self::ISSUER . '/authorize',
            tokenEndpoint: self::ISSUER . '/token',
            jwksUri: self::ISSUER . '/jwks',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function issueToken(array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'user-42',
            'nonce' => 'expected-nonce',
            'iat' => time(),
            'exp' => time() + 300,
        ], $overrides);

        // JWT::decode() against a keyset array (as real JWKS verification
        // always is) matches by "kid" — the mocked keyset below is keyed
        // 'kid', so tokens must carry a matching header for the "valid
        // token" tests to actually exercise signature verification.
        return JWT::encode($claims, self::SECRET, 'HS256', 'kid');
    }

    private function verifierWithStubbedKeys(): IdTokenVerifier
    {
        $keyProvider = $this->createStub(JwksKeyProviderInterface::class);
        $keyProvider->method('keysFor')->willReturn(['kid' => new Key(self::SECRET, 'HS256')]);

        return new IdTokenVerifier($keyProvider);
    }

    public function testAcceptsAValidToken(): void
    {
        $claims = $this->verifierWithStubbedKeys()->verify(
            $this->issueToken(),
            $this->provider(),
            $this->discovery(),
            'expected-nonce',
        );

        static::assertSame('user-42', $claims['sub']);
    }

    public function testRejectsAWrongIssuer(): void
    {
        $this->expectException(OidcAuthenticationException::class);
        $this->expectExceptionMessageMatches('/issuer/');

        $this->verifierWithStubbedKeys()->verify(
            $this->issueToken(['iss' => 'https://attacker.example.com']),
            $this->provider(),
            $this->discovery(),
            'expected-nonce',
        );
    }

    public function testRejectsAWrongAudience(): void
    {
        $this->expectException(OidcAuthenticationException::class);
        $this->expectExceptionMessageMatches('/audience/');

        $this->verifierWithStubbedKeys()->verify(
            $this->issueToken(['aud' => 'some-other-client']),
            $this->provider(),
            $this->discovery(),
            'expected-nonce',
        );
    }

    public function testAcceptsAudienceAsASingleEntryArrayContainingTheClientId(): void
    {
        $claims = $this->verifierWithStubbedKeys()->verify(
            $this->issueToken(['aud' => [self::CLIENT_ID]]),
            $this->provider(),
            $this->discovery(),
            'expected-nonce',
        );

        static::assertSame('user-42', $claims['sub']);
    }

    public function testAcceptsMultipleAudiencesWhenTheAuthorizedPartyIsThisClient(): void
    {
        $claims = $this->verifierWithStubbedKeys()->verify(
            $this->issueToken(['aud' => ['some-other-client', self::CLIENT_ID], 'azp' => self::CLIENT_ID]),
            $this->provider(),
            $this->discovery(),
            'expected-nonce',
        );

        static::assertSame('user-42', $claims['sub']);
    }

    /**
     * OIDC Core §3.1.3.7 step 4: without `azp`, a multi-audience token gives
     * no evidence about which client it was actually minted for.
     */
    public function testRejectsMultipleAudiencesWithoutAnAuthorizedParty(): void
    {
        $this->expectException(OidcAuthenticationException::class);
        $this->expectExceptionMessageMatches('/azp/');

        $this->verifierWithStubbedKeys()->verify(
            $this->issueToken(['aud' => ['some-other-client', self::CLIENT_ID]]),
            $this->provider(),
            $this->discovery(),
            'expected-nonce',
        );
    }

    /**
     * The attack this closes: a token the IdP minted for a *different* client
     * that merely lists us in `aud` must not log that client's holder in here.
     */
    public function testRejectsAnAuthorizedPartyBelongingToAnotherClient(): void
    {
        $this->expectException(OidcAuthenticationException::class);
        $this->expectExceptionMessageMatches('/authorized party/');

        $this->verifierWithStubbedKeys()->verify(
            $this->issueToken(['aud' => [self::CLIENT_ID, 'some-other-client'], 'azp' => 'some-other-client']),
            $this->provider(),
            $this->discovery(),
            'expected-nonce',
        );
    }

    public function testRejectsAMismatchedNonce(): void
    {
        $this->expectException(OidcAuthenticationException::class);
        $this->expectExceptionMessageMatches('/nonce/');

        $this->verifierWithStubbedKeys()->verify(
            $this->issueToken(),
            $this->provider(),
            $this->discovery(),
            'a-different-nonce-than-the-one-in-the-token',
        );
    }

    public function testRejectsAMissingNonce(): void
    {
        $token = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'user-42',
            'iat' => time(),
            'exp' => time() + 300,
        ], self::SECRET, 'HS256', 'kid');

        $this->expectException(OidcAuthenticationException::class);

        $this->verifierWithStubbedKeys()->verify($token, $this->provider(), $this->discovery(), 'expected-nonce');
    }

    public function testRejectsAnExpiredToken(): void
    {
        $this->expectException(OidcAuthenticationException::class);

        $this->verifierWithStubbedKeys()->verify(
            $this->issueToken(['exp' => time() - 60]),
            $this->provider(),
            $this->discovery(),
            'expected-nonce',
        );
    }

    public function testRejectsATokenSignedWithAnUnknownKey(): void
    {
        $forged = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'user-42',
            'nonce' => 'expected-nonce',
            'iat' => time(),
            'exp' => time() + 300,
        ], 'a-completely-different-secret-the-idp-never-issued', 'HS256', 'kid');

        $this->expectException(OidcAuthenticationException::class);

        $this->verifierWithStubbedKeys()->verify($forged, $this->provider(), $this->discovery(), 'expected-nonce');
    }
}
