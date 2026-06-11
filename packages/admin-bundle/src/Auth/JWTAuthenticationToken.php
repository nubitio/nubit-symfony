<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Override;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

class JWTAuthenticationToken extends PostAuthenticationToken
{
    public function __construct(UserInterface $user, string $firewallName, array $roles, private readonly string $token)
    {
        parent::__construct($user, $firewallName, $roles);
    }

    #[Override]
    public function getCredentials(): string
    {
        return $this->token;
    }
}
