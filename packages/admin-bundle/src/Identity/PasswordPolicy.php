<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Nubit\AdminBundle\Identity\Exception\IdentityException;

/**
 * The one password rule every write path has to share.
 *
 * Change-password already required eight characters. Reset and invitation
 * acceptance did not, so the weakest door was the one used after a suspected
 * theft. One policy, applied at the gateway, closes that.
 */
final readonly class PasswordPolicy
{
    public const int MIN_LENGTH = 8;

    public static function assertAcceptable(string $plainPassword): void
    {
        if (strlen($plainPassword) < self::MIN_LENGTH) {
            throw new IdentityException(sprintf('Passwords must be at least %d characters.', self::MIN_LENGTH));
        }
    }
}
