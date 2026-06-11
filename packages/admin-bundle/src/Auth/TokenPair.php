<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

/**
 * Access + refresh token pair produced on login/refresh, together with the
 * response payload describing the authenticated user.
 */
final readonly class TokenPair
{
    /**
     * @param array<string, mixed> $userData
     */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $accessTokenExpiresAt,
        public int $refreshTokenExpiresAt,
        public array $userData,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeTokens = true): array
    {
        $data = ['user' => $this->userData];

        if ($includeTokens) {
            $data['token'] = $this->accessToken;
            $data['refreshToken'] = $this->refreshToken;
            $data['expiresAt'] = $this->accessTokenExpiresAt;
        }

        return $data;
    }
}
