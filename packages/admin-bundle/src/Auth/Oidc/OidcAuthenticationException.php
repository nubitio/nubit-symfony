<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class OidcAuthenticationException extends AuthenticationException {}
