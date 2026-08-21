<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Maps verified ID token claims to an app user. This bundle doesn't know the
 * app's User class (same reason as TokenClaimsProviderInterface) — implement
 * this to decide provisioning policy: look up an existing user by email/sub,
 * create one on first login, reject unknown users, map IdP groups to roles…
 */
interface OidcUserResolverInterface
{
    /**
     * @param array<string, mixed> $claims Verified ID token claims (sub, email, name, …).
     *
     * @throws OidcAuthenticationException If the claims don't resolve to an allowed user.
     */
    public function resolve(array $claims, OidcProviderConfig $provider): UserInterface;
}
