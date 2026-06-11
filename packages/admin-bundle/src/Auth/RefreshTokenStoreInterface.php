<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use DateTimeImmutable;

/**
 * Persistence for refresh tokens (rotation + revocation). The bundle ships a
 * Doctrine implementation; swap it for Redis or any other backend by
 * redefining the service.
 */
interface RefreshTokenStoreInterface
{
    public function save(
        string $jti,
        string $tokenHash,
        string $userIdentifier,
        DateTimeImmutable $expiresAt,
    ): void;

    /** Whether an unexpired, unrevoked token with this hash exists. */
    public function isActiveByHash(string $tokenHash): bool;

    /** Revokes the token with this hash (used on rotation and logout). */
    public function revokeByHash(string $tokenHash): void;

    /** Revokes every active token of a user. Returns the revoked count. */
    public function revokeAllForUser(string $userIdentifier): int;

    /** Deletes expired and long-revoked tokens. Returns the deleted count. */
    public function purgeExpired(): int;
}
