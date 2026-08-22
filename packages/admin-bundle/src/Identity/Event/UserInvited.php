<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Event;

/**
 * An invitation was created and needs delivering.
 *
 * Same reasoning as {@see PasswordResetRequested}: the token exists only here,
 * and the channel belongs to the application.
 */
final readonly class UserInvited
{
    /** @param list<string> $roles */
    public function __construct(
        public string $email,
        public string $token,
        public array $roles,
        public \DateTimeImmutable $expiresAt,
        public ?string $invitedBy = null,
    ) {}
}
