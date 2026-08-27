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
}
