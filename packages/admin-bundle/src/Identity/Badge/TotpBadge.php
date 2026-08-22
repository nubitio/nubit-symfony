<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Badge;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/**
 * Carries the second-factor code from the login request to the listener that
 * checks it.
 *
 * A badge rather than a check inside the authenticator, so it runs *after*
 * Symfony has verified the password. The order matters: asking for a code
 * before the password is known to be right would let anyone enumerate which
 * accounts have a second factor.
 */
final class TotpBadge implements BadgeInterface
{
    private bool $resolved = false;

    public function __construct(
        private readonly string $code,
    ) {}

    public function getCode(): string
    {
        return $this->code;
    }

    public function markResolved(): void
    {
        $this->resolved = true;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
