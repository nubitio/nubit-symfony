<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Event;

/**
 * A reset token was issued and needs delivering.
 *
 * Carries the plaintext token because this is the only moment it exists. The
 * listener that sends it is the application's: which channel a reset goes out
 * on is a product decision, and the bundle must not require a mailer to offer
 * password recovery.
 */
final readonly class PasswordResetRequested
{
    public function __construct(
        public string $userIdentifier,
        public string $token,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
