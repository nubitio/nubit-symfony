<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Auth;

use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Tests\Integration\Fixture\Entity\TestUser;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The full authentication cycle over HTTP.
 *
 * Token issuing, cookie flags, rotation and revocation are decisions spread
 * across an authenticator, two controllers, a cookie factory and a Doctrine
 * refresh-token store. Each has unit tests; none of them can answer whether a
 * revoked refresh token is actually refused, because that answer only exists
 * once all five run against a real database in one request pipeline.
 */
#[CoversNothing]
final class JwtAuthenticationTest extends IntegrationTestCase
{
    private const string EMAIL = 'admin@example.com';
    private const string PASSWORD = 'admin1234';

    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => [
                        'secret' => '%env(APP_SECRET)%',
                        'access_token_ttl' => 3600,
                        'refresh_token_ttl' => 1209600,
                        'cookie_secure' => true,
                    ],
                ],
            ],
            self::fixtureMapping(),
            $this->securityConfig(),
        );

        $this->resetSchema();
        $this->seedUser();
    }

    public function testLoginIssuesAccessAndRefreshCookies(): void
    {
        $response = $this->login();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $cookies = $this->cookiesFrom($response);
        self::assertArrayHasKey(JWTAuthenticator::AUTH_COOKIE, $cookies);
        self::assertArrayHasKey(JWTAuthenticator::REFRESH_COOKIE, $cookies);
    }

    /**
     * A JWT readable by JavaScript is a JWT one XSS away from being stolen, and
     * one sent over plain HTTP is one network away. Both flags are configuration
     * that a refactor can silently drop.
     */
    public function testAuthCookiesAreHttpOnlyAndSecure(): void
    {
        $response = $this->login();

        foreach ([JWTAuthenticator::AUTH_COOKIE, JWTAuthenticator::REFRESH_COOKIE] as $name) {
            $cookie = $this->cookiesFrom($response)[$name] ?? null;
            self::assertNotNull($cookie, sprintf('Cookie %s was not set.', $name));
            self::assertTrue($cookie->isHttpOnly(), sprintf('%s must be HttpOnly.', $name));
            self::assertTrue($cookie->isSecure(), sprintf('%s must be Secure.', $name));
        }
    }

    public function testWrongPasswordIsRejected(): void
    {
        $response = $this->login(password: 'not-the-password');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertArrayNotHasKey(JWTAuthenticator::AUTH_COOKIE, $this->cookiesFrom($response));
    }

    public function testProtectedRouteRejectsAnonymousRequests(): void
    {
        $response = $this->jsonRequest('GET', '/api/me');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAccessTokenGrantsAccessAsBearerAndAsCookie(): void
    {
        $login = $this->login();
        $accessToken = $this->cookieValue($login, JWTAuthenticator::AUTH_COOKIE);

        $bearer = $this->jsonRequest('GET', '/api/me', headers: ['Authorization' => 'Bearer ' . $accessToken]);
        self::assertSame(Response::HTTP_OK, $bearer->getStatusCode());

        $cookie = $this->jsonRequest('GET', '/api/me', cookies: [JWTAuthenticator::AUTH_COOKIE => $accessToken]);
        self::assertSame(Response::HTTP_OK, $cookie->getStatusCode());
    }

    /** A refresh token is not an access token, and must not be usable as one. */
    public function testRefreshTokenIsNotAcceptedAsAnAccessToken(): void
    {
        $login = $this->login();
        $refreshToken = $this->cookieValue($login, JWTAuthenticator::REFRESH_COOKIE);

        $response = $this->jsonRequest('GET', '/api/me', headers: ['Authorization' => 'Bearer ' . $refreshToken]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testRefreshRotatesTheTokenPair(): void
    {
        $login = $this->login();
        $refreshToken = $this->cookieValue($login, JWTAuthenticator::REFRESH_COOKIE);

        $refreshed = $this->jsonRequest('POST', '/api/auth/refresh', cookies: [
            JWTAuthenticator::REFRESH_COOKIE => $refreshToken,
        ]);

        self::assertSame(Response::HTTP_OK, $refreshed->getStatusCode());

        $rotated = $this->cookieValue($refreshed, JWTAuthenticator::REFRESH_COOKIE);
        self::assertNotSame($refreshToken, $rotated, 'Refresh must rotate the token, not reissue the same one.');

        $newAccessToken = $this->cookieValue($refreshed, JWTAuthenticator::AUTH_COOKIE);
        $me = $this->jsonRequest('GET', '/api/me', headers: ['Authorization' => 'Bearer ' . $newAccessToken]);
        self::assertSame(Response::HTTP_OK, $me->getStatusCode());
    }

    /**
     * Rotation without invalidation is not rotation. If a stolen refresh token
     * keeps working after the legitimate client refreshes, the whole rotation
     * scheme buys nothing.
     */
    public function testConsumedRefreshTokenCannotBeReused(): void
    {
        $login = $this->login();
        $refreshToken = $this->cookieValue($login, JWTAuthenticator::REFRESH_COOKIE);

        $this->jsonRequest('POST', '/api/auth/refresh', cookies: [JWTAuthenticator::REFRESH_COOKIE => $refreshToken]);

        $replayed = $this->jsonRequest('POST', '/api/auth/refresh', cookies: [
            JWTAuthenticator::REFRESH_COOKIE => $refreshToken,
        ]);

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $replayed->getStatusCode(),
            'A refresh token survived being consumed.',
        );
    }

    public function testLogoutRevokesTheRefreshToken(): void
    {
        $login = $this->login();
        $refreshToken = $this->cookieValue($login, JWTAuthenticator::REFRESH_COOKIE);
        $accessToken = $this->cookieValue($login, JWTAuthenticator::AUTH_COOKIE);

        $logout = $this->jsonRequest(
            'POST',
            '/api/auth/logout',
            headers: ['Authorization' => 'Bearer ' . $accessToken],
            cookies: [JWTAuthenticator::REFRESH_COOKIE => $refreshToken],
        );
        self::assertSame(Response::HTTP_OK, $logout->getStatusCode());

        $afterLogout = $this->jsonRequest('POST', '/api/auth/refresh', cookies: [
            JWTAuthenticator::REFRESH_COOKIE => $refreshToken,
        ]);

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $afterLogout->getStatusCode(),
            'The refresh token still worked after logout.',
        );
    }

    public function testGarbageTokenIsRejected(): void
    {
        $response = $this->jsonRequest('GET', '/api/me', headers: ['Authorization' => 'Bearer not.a.jwt']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * A JWT signed with a different secret must not be accepted. This is the
     * check that stands between the API and anyone who can mint their own
     * tokens.
     */
    public function testTokenSignedWithAnotherSecretIsRejected(): void
    {
        $forged = \Firebase\JWT\JWT::encode(
            [
                'username' => self::EMAIL,
                'type' => 'access',
                'exp' => time() + 3600,
            ],
            'a-completely-different-secret-of-sufficient-length',
            'HS256',
        );

        $response = $this->jsonRequest('GET', '/api/me', headers: ['Authorization' => 'Bearer ' . $forged]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function securityConfig(): array
    {
        return [
            'password_hashers' => [
                \Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface::class => [
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'app_users' => ['entity' => ['class' => TestUser::class, 'property' => 'email']],
            ],
            'firewalls' => [
                'api' => [
                    'pattern' => '^/api',
                    'stateless' => true,
                    'provider' => 'app_users',
                    'custom_authenticator' => JWTAuthenticator::class,
                ],
            ],
            'access_control' => [
                ['path' => '^/api/auth/(login|refresh)', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api', 'roles' => 'ROLE_USER'],
            ],
        ];
    }

    private function seedUser(): void
    {
        $hasher = $this->container()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new TestUser();
        $user->setEmail(self::EMAIL)->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $entityManager = $this->entityManager();
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function login(string $email = self::EMAIL, string $password = self::PASSWORD): Response
    {
        return $this->jsonRequest('POST', '/api/auth/login', body: ['username' => $email, 'password' => $password]);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function jsonRequest(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        array $cookies = [],
    ): Response {
        $this->entityManager()->clear();

        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        $request = Request::create(
            $path,
            $method,
            [],
            $cookies,
            [],
            $server,
            [] === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        if (null === $this->kernel) {
            self::fail('Boot the kernel before issuing requests.');
        }

        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /** @return array<string, \Symfony\Component\HttpFoundation\Cookie> */
    private function cookiesFrom(Response $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        return $cookies;
    }

    private function cookieValue(Response $response, string $name): string
    {
        $cookie = $this->cookiesFrom($response)[$name] ?? null;
        self::assertNotNull($cookie, sprintf('Cookie %s was not set.', $name));

        $value = $cookie->getValue();
        self::assertIsString($value);
        self::assertNotSame('', $value);

        return $value;
    }
}
