<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Support;

use DateTimeImmutable;
use Nubit\AdminBundle\Auth\RefreshTokenStoreInterface;

final class InMemoryRefreshTokenStore implements RefreshTokenStoreInterface
{
    /** @var array<string, array{jti: string, userIdentifier: string, expiresAt: DateTimeImmutable, revoked: bool}> */
    public array $tokens = [];

    public function save(string $jti, string $tokenHash, string $userIdentifier, DateTimeImmutable $expiresAt): void
    {
        $this->tokens[$tokenHash] = [
            'jti' => $jti,
            'userIdentifier' => $userIdentifier,
            'expiresAt' => $expiresAt,
            'revoked' => false,
        ];
    }

    public function isActiveByHash(string $tokenHash): bool
    {
        $token = $this->tokens[$tokenHash] ?? null;

        return null !== $token && !$token['revoked'] && $token['expiresAt'] > new DateTimeImmutable();
    }

    public function revokeByHash(string $tokenHash): void
    {
        if (isset($this->tokens[$tokenHash])) {
            $this->tokens[$tokenHash]['revoked'] = true;
        }
    }

    public function revokeAllForUser(string $userIdentifier): int
    {
        $count = 0;
        foreach ($this->tokens as $hash => $token) {
            if ($token['userIdentifier'] === $userIdentifier && !$token['revoked']) {
                $this->tokens[$hash]['revoked'] = true;
                ++$count;
            }
        }

        return $count;
    }

    public function purgeExpired(): int
    {
        return 0;
    }
}
