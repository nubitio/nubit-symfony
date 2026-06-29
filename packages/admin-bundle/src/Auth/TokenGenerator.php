<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Issues access/refresh token pairs. Claims come from the configured
 * TokenClaimsProviderInterface; refresh tokens are persisted (hashed) through
 * the RefreshTokenStoreInterface for rotation/revocation.
 */
final readonly class TokenGenerator
{
    public function __construct(
        private JWTManagerInterface $jwtManager,
        private TokenClaimsProviderInterface $claimsProvider,
        private RefreshTokenStoreInterface $refreshTokenStore,
        private int $accessTokenTtl,
        private int $refreshTokenTtl,
    ) {
    }

    /**
     * @param array<string, mixed> $previousClaims Claims of the rotated token on refresh.
     */
    public function generateTokenPair(UserInterface $user, array $previousClaims = []): TokenPair
    {
        $now = time();
        $accessTokenExpiresAt = $now + $this->accessTokenTtl;
        $refreshTokenExpiresAt = $now + $this->refreshTokenTtl;

        $payload = [
            ...$this->claimsProvider->claims($user, $previousClaims),
            'iat' => $now,
            'type' => 'access',
            'username' => $user->getUserIdentifier(),
        ];

        $accessToken = $this->jwtManager->encode([
            ...$payload,
            'exp' => $accessTokenExpiresAt,
        ]);

        $refreshTokenJti = bin2hex(random_bytes(16));
        $refreshToken = $this->jwtManager->encode([
            ...$payload,
            'exp' => $refreshTokenExpiresAt,
            'type' => 'refresh',
            'jti' => $refreshTokenJti,
        ]);

        $this->refreshTokenStore->save(
            $refreshTokenJti,
            hash('sha256', $refreshToken),
            $user->getUserIdentifier(),
            (new DateTimeImmutable())->setTimestamp($refreshTokenExpiresAt),
        );

        return new TokenPair(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            accessTokenExpiresAt: $accessTokenExpiresAt,
            refreshTokenExpiresAt: $refreshTokenExpiresAt,
            userData: $this->claimsProvider->userData($user),
        );
    }

    public function getAccessTokenTtl(): int
    {
        return $this->accessTokenTtl;
    }

    public function getRefreshTokenTtl(): int
    {
        return $this->refreshTokenTtl;
    }
}
