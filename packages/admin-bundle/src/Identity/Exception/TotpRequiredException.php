<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Exception;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The password was right and a second factor is still needed.
 *
 * Distinguishable from a bad password on purpose. It does tell an attacker
 * holding valid credentials that the account has a second factor — which is
 * unavoidable, because the legitimate user has to be told to reach for their
 * phone, and a 2FA prompt nobody can see is a 2FA nobody can pass.
 */
final class TotpRequiredException extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'Second factor required.';
    }
}
