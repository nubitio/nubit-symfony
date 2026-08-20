<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth;

use Nubit\AdminBundle\Auth\CookieFactory;
use Nubit\AdminBundle\Auth\DefaultTokenClaimsProvider;
use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\Auth\JWTManager;
use Nubit\AdminBundle\Auth\ResponseModeResolver;
use Nubit\AdminBundle\Auth\TokenClaimsProviderInterface;
use Nubit\AdminBundle\Auth\TokenGenerator;
use Nubit\AdminBundle\Tests\Support\InMemoryRefreshTokenStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class JWTAuthenticatorTest extends TestCase
{
    private JWTManager $jwtManager;
    private TokenGenerator $tokenGenerator;
    private JWTAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->jwtManager = new JWTManager('test-secret-key-with-32-or-more-chars!', new NullLogger());
        $claimsProvider = new class implements TokenClaimsProviderInterface {
            public function claims(UserInterface $user, array $previousClaims = []): array
            {
                return ['roles' => $user->getRoles()];
            }

            public function userData(UserInterface $user): array
            {
                return ['username' => $user->getUserIdentifier(), 'roles' => $user->getRoles()];
            }
        };

        $this->tokenGenerator = new TokenGenerator(
            $this->jwtManager,
            $claimsProvider,
            new InMemoryRefreshTokenStore(),
            accessTokenTtl: 3600,
            refreshTokenTtl: 7200,
        );

        /** @implements UserProviderInterface<InMemoryUser> */
        $userProvider = new class implements UserProviderInterface {
            public function refreshUser(UserInterface $user): UserInterface
            {
                return $user;
            }

            public function supportsClass(string $class): bool
            {
                return true;
            }

            public function loadUserByIdentifier(string $identifier): UserInterface
            {
                return new InMemoryUser($identifier, null, ['ROLE_USER']);
            }
        };

        $this->authenticator = new JWTAuthenticator(
            $userProvider,
            $this->jwtManager,
            $this->tokenGenerator,
            new ResponseModeResolver(),
            new CookieFactory(),
            new NullLogger(),
        );
    }

    public function testAuthenticateAcceptsAccessToken(): void
    {
        $pair = $this->tokenGenerator->generateTokenPair(new InMemoryUser('jane@example.com', null, ['ROLE_USER']));

        $request = Request::create('/api/protected');
        $request->cookies->set(JWTAuthenticator::AUTH_COOKIE, $pair->accessToken);

        $passport = $this->authenticator->authenticate($request);

        self::assertSame($pair->accessToken, $passport->getAttribute('token'));
    }

    public function testAuthenticateRejectsRefreshTokenUsedAsAccessToken(): void
    {
        $pair = $this->tokenGenerator->generateTokenPair(new InMemoryUser('jane@example.com', null, ['ROLE_USER']));

        $request = Request::create('/api/protected');
        $request->cookies->set(JWTAuthenticator::AUTH_COOKIE, $pair->refreshToken);

        $this->expectException(AuthenticationException::class);

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateAcceptsAccessTokenWithNoActiveTenant(): void
    {
        // DefaultTokenClaimsProvider (the bundle's default) sets tenantName
        // explicitly to null when no TenantContext is active — that must not
        // be mistaken for a tampered claim and reject every request.
        $generator = new TokenGenerator(
            $this->jwtManager,
            new DefaultTokenClaimsProvider(),
            new InMemoryRefreshTokenStore(),
            accessTokenTtl: 3600,
            refreshTokenTtl: 7200,
        );
        $pair = $generator->generateTokenPair(new InMemoryUser('jane@example.com', null, ['ROLE_USER']));

        $request = Request::create('/api/protected');
        $request->cookies->set(JWTAuthenticator::AUTH_COOKIE, $pair->accessToken);

        $passport = $this->authenticator->authenticate($request);

        self::assertSame($pair->accessToken, $passport->getAttribute('token'));
    }
}
