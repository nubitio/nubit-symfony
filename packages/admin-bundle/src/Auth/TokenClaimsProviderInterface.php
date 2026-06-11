<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Supplies the application-specific parts of the JWT payload and of the
 * login/refresh response body. Override the default implementation to add
 * claims like user id, role id, branch, or tenant.
 */
interface TokenClaimsProviderInterface
{
    /**
     * Extra JWT claims merged into the token payload. The bundle always sets
     * `iat`, `exp`, `type`, `username` (the Symfony user identifier), and
     * `jti` (refresh only) — returned claims must not collide with those.
     *
     * @param array<string, mixed> $previousClaims On refresh, the claims of the
     *                                             token being rotated (empty on login) —
     *                                             lets providers carry over context.
     *
     * @return array<string, mixed>
     */
    public function claims(UserInterface $user, array $previousClaims = []): array;

    /**
     * The `user` object returned in the login/refresh response body.
     *
     * @return array<string, mixed>
     */
    public function userData(UserInterface $user): array;
}
