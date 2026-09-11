<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who is calling, and whether they may act as an administrator.
 *
 * Custom bundle routes sit outside API Platform's operation `security:`
 * expressions. Without a shared check they all degrade to "any ROLE_USER",
 * which is how inviting with ROLE_ADMIN and downloading somebody else's
 * import become the same endpoint.
 */
final readonly class PrivilegedAccess
{
    /** @param list<string> $adminRoles */
    public function __construct(
        private Security $security,
        private array $adminRoles = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'],
    ) {}

    public function user(): UserInterface
    {
        return $this->security->getUser() ?? throw new AccessDeniedHttpException();
    }

    public function identifier(): string
    {
        return $this->user()->getUserIdentifier();
    }

    public function isAdmin(): bool
    {
        foreach ($this->adminRoles as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    public function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * The caller is the owner, or an administrator acting for them.
     *
     * A 404, not a 403, when they are neither: telling those apart turns an
     * identifier into a way to learn what other people own.
     */
    public function ownsOrAdmin(string $ownerIdentifier): bool
    {
        return $this->identifier() === $ownerIdentifier || $this->isAdmin();
    }

    /**
     * Refuses a set of roles that reaches past what the caller already holds.
     *
     * Issuing a credential — an invitation, an API key — with a role the
     * issuer does not themselves have is a privilege escalation regardless of
     * who the credential is issued *for*: a clerk must not be able to mint an
     * admin's API key any more than they could grant themselves ROLE_ADMIN
     * directly. Checked with `isGranted()`, not a plain array comparison, so
     * a role hierarchy is honoured the same way it would be anywhere else.
     *
     * @param list<string> $roles
     */
    public function assertRolesWithinAuthority(array $roles): void
    {
        foreach ($roles as $role) {
            if (!$this->security->isGranted($role)) {
                throw new AccessDeniedHttpException(\sprintf(
                    'Cannot grant "%s": it is not part of your own roles.',
                    $role,
                ));
            }
        }
    }
}
