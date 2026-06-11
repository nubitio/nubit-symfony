<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth;

use Nubit\AdminBundle\Auth\DefaultTokenClaimsProvider;
use Nubit\AdminBundle\Auth\JWTManager;
use Nubit\AdminBundle\Tests\Support\InMemoryRefreshTokenStore;
use Nubit\AdminBundle\Auth\TokenGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TokenGeneratorTest extends TestCase
{
    private JWTManager $jwtManager;
    private InMemoryRefreshTokenStore $store;
    private TokenGenerator $generator;

    protected function setUp(): void
    {
        $this->jwtManager = new JWTManager('test-secret-key-with-32-or-more-chars!', new NullLogger());
        $this->store = new InMemoryRefreshTokenStore();
        $this->generator = new TokenGenerator(
            $this->jwtManager,
            new DefaultTokenClaimsProvider(),
            $this->store,
            accessTokenTtl: 3600,
            refreshTokenTtl: 7200,
        );
    }

    public function testGeneratesDecodableTokenPair(): void
    {
        $user = new InMemoryUser('jane@example.com', null, ['ROLE_USER']);

        $pair = $this->generator->generateTokenPair($user);

        $access = $this->jwtManager->decode($pair->accessToken);
        self::assertSame('access', $access['type']);
        self::assertSame('jane@example.com', $access['username']);
        self::assertSame(['ROLE_USER'], $access['roles']);
        self::assertSame($pair->accessTokenExpiresAt, $access['exp']);

        $refresh = $this->jwtManager->decode($pair->refreshToken);
        self::assertSame('refresh', $refresh['type']);
        self::assertNotEmpty($refresh['jti']);
        self::assertSame($pair->refreshTokenExpiresAt, $refresh['exp']);
    }

    public function testPersistsHashedRefreshToken(): void
    {
        $user = new InMemoryUser('jane@example.com', null, ['ROLE_USER']);

        $pair = $this->generator->generateTokenPair($user);

        $hash = hash('sha256', $pair->refreshToken);
        self::assertTrue($this->store->isActiveByHash($hash));
        self::assertSame('jane@example.com', $this->store->tokens[$hash]['userIdentifier']);
        // The raw token must never be stored.
        self::assertArrayNotHasKey($pair->refreshToken, $this->store->tokens);
    }

    public function testRefreshTtlOutlivesAccessTtl(): void
    {
        $user = new InMemoryUser('jane@example.com', null, ['ROLE_USER']);

        $pair = $this->generator->generateTokenPair($user);

        self::assertGreaterThan($pair->accessTokenExpiresAt, $pair->refreshTokenExpiresAt);
    }

    public function testUserDataComesFromClaimsProvider(): void
    {
        $user = new InMemoryUser('jane@example.com', null, ['ROLE_USER']);

        $pair = $this->generator->generateTokenPair($user);

        self::assertSame('jane@example.com', $pair->userData['username']);
        self::assertSame(['ROLE_USER'], $pair->userData['roles']);
    }
}
