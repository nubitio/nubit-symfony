<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Controller;

use LogicException;

/**
 * Never executed: POST requests to the login route are intercepted by
 * JWTAuthenticator. The route only needs to exist for the security system.
 */
final class LoginController
{
    public function __invoke(): never
    {
        throw new LogicException('This route is handled by the JWT authenticator.');
    }
}
