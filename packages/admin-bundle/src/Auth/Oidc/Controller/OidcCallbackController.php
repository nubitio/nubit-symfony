<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc\Controller;

use LogicException;

/**
 * Never executed: requests to the callback route are intercepted by
 * OidcAuthenticator. The route only needs to exist for the security system —
 * same reasoning as Nubit\AdminBundle\Controller\LoginController.
 */
final class OidcCallbackController
{
    public function __invoke(): never
    {
        throw new LogicException('This route is handled by the OIDC authenticator.');
    }
}
