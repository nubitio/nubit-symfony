<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth\Oidc;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Nubit\AdminBundle\Auth\CookieFactory;
use Nubit\AdminBundle\Auth\DefaultTokenClaimsProvider;
use Nubit\AdminBundle\Auth\JWTManager;
use Nubit\AdminBundle\Auth\Oidc\Controller\OidcRedirectController;
use Nubit\AdminBundle\Auth\Oidc\IdTokenVerifier;
use Nubit\AdminBundle\Auth\Oidc\JwksKeyProviderInterface;
use Nubit\AdminBundle\Auth\Oidc\OidcAuthenticationException;
use Nubit\AdminBundle\Auth\Oidc\OidcAuthenticator;
use Nubit\AdminBundle\Auth\Oidc\OidcDiscoveryClient;
use Nubit\AdminBundle\Auth\Oidc\OidcFlowState;
use Nubit\AdminBundle\Auth\Oidc\OidcFlowStateCodec;
use Nubit\AdminBundle\Auth\Oidc\OidcProviderConfig;
use Nubit\AdminBundle\Auth\Oidc\OidcProviderRegistry;
use Nubit\AdminBundle\Auth\Oidc\OidcUserResolverInterface;
use Nubit\AdminBundle\Auth\TokenGenerator;
use Nubit\AdminBundle\Tests\Support\InMemoryRefreshTokenStore;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Covers the callback leg end to end with a stubbed IdP: the flow cookie, the
 * state check, the code exchange and the resulting passport. The happy path
 * matters beyond its assertions — it is the only place that executes
 * `authenticate()`'s references to OidcRedirectController::FLOW_COOKIE, which
 * a missing import turns into a fatal "class not found" at runtime rather
 * than a failure any of the unit-level OIDC tests would notice.
 */
final class OidcAuthenticatorTest extends TestCase
{
    private const string SECRET = 'a-signing-secret-at-least-32-bytes-long';
    private const string ISSUER = 'https://idp.example.com';
    private const string CLIENT_ID = 'test-client-id';

    public function testAuthenticatesACallbackThatMatchesTheFlowCookie(): void
    {
        $passport = $this->authenticator()->authenticate($this->callbackRequest());

        static::assertSame('alice@example.com', $passport->getUser()->getUserIdentifier());
    }

    public function testRejectsACallbackWithNoFlowCookie(): void
    {
        $request = $this->callbackRequest();
        $request->cookies->remove(OidcRedirectController::FLOW_COOKIE);

        $this->expectException(OidcAuthenticationException::class);
        $this->expectExceptionMessageMatches('/Missing or expired/');

        $this->authenticator()->authenticate($request);
    }

    public function testRejectsACallbackWhoseStateDoesNotMatchTheFlowCookie(): void
    {
        $request = $this->callbackRequest(returnedState: 'a-state-we-never-issued');

        $this->expectException(OidcAuthenticationException::class);
        $this->expectExceptionMessageMatches('/state mismatch/');

        $this->authenticator()->authenticate($request);
    }

    public function testRejectsAFlowCookieIssuedForAnotherProvider(): void
    {
        $request = $this->callbackRequest(cookieProvider: 'another-idp');

        $this->expectException(OidcAuthenticationException::class);
        $this->expectExceptionMessageMatches('/provider mismatch/');

        $this->authenticator()->authenticate($request);
    }

    public function testRejectsAnUnknownProvider(): void
    {
        $request = $this->callbackRequest();
        $request->attributes->set('provider', 'not-configured');

        $this->expectException(OidcAuthenticationException::class);
        $this->expectExceptionMessageMatches('/Unknown OIDC provider/');

        $this->authenticator()->authenticate($request);
    }

    private function callbackRequest(
        string $returnedState = 'state-123',
        string $cookieProvider = 'test',
    ): Request {
        $flowState = new OidcFlowState($cookieProvider, 'state-123', 'nonce-456', 'verifier-789', time());

        $request = Request::create('/api/auth/oidc/test/callback', 'GET', [
            'state' => $returnedState,
            'code' => 'an-authorization-code',
        ], [
            OidcRedirectController::FLOW_COOKIE => (new OidcFlowStateCodec(self::SECRET))->encode($flowState),
        ]);
        $request->attributes->set('_route', OidcAuthenticator::CALLBACK_ROUTE);
        $request->attributes->set('provider', 'test');

        return $request;
    }

    private function authenticator(): OidcAuthenticator
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse($this->discoveryDocument()),
            new JsonMockResponse(['id_token' => $this->idToken()]),
        ]);

        return new OidcAuthenticator(
            new OidcProviderRegistry(['test' => [
                'issuer' => self::ISSUER,
                'client_id' => self::CLIENT_ID,
                'client_secret' => 'a-client-secret',
                'scopes' => ['openid'],
                'redirect_uri' => 'https://app.example.com/api/auth/oidc/test/callback',
                'post_login_redirect_uri' => 'https://app.example.com/',
            ]]),
            new OidcDiscoveryClient($httpClient, new ArrayAdapter()),
            new IdTokenVerifier($this->jwksKeyProvider()),
            $this->userResolver(),
            new OidcFlowStateCodec(self::SECRET),
            new TokenGenerator(
                new JWTManager(self::SECRET, new NullLogger()),
                new DefaultTokenClaimsProvider(),
                new InMemoryRefreshTokenStore(),
                accessTokenTtl: 3600,
                refreshTokenTtl: 7200,
            ),
            new CookieFactory(),
            $httpClient,
            new NullLogger(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function discoveryDocument(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
            'jwks_uri' => self::ISSUER . '/jwks',
        ];
    }

    private function idToken(): string
    {
        return JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'user-42',
            'email' => 'alice@example.com',
            'nonce' => 'nonce-456',
            'iat' => time(),
            'exp' => time() + 300,
        ], self::SECRET, 'HS256', 'kid');
    }

    private function jwksKeyProvider(): JwksKeyProviderInterface
    {
        $keyProvider = $this->createStub(JwksKeyProviderInterface::class);
        $keyProvider->method('keysFor')->willReturn(['kid' => new Key(self::SECRET, 'HS256')]);

        return $keyProvider;
    }

    private function userResolver(): OidcUserResolverInterface
    {
        return new class () implements OidcUserResolverInterface {
            public function resolve(array $claims, OidcProviderConfig $provider): UserInterface
            {
                if (!isset($claims['email']) || !is_string($claims['email'])) {
                    throw new OidcAuthenticationException('ID token carries no email claim.');
                }

                return new InMemoryUser($claims['email'], null, ['ROLE_USER']);
            }
        };
    }
}
