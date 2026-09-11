<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

use Nubit\TenantBundle\Contract\TenantAwareUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Wraps a resolver whose tenant identifier comes from something a caller
 * controls directly — a header, a subdomain — rather than from server-issued
 * state, and refuses to trust it blindly for an authenticated caller.
 *
 * An anonymous request is left alone: whether an anonymous caller may reach a
 * given route at all is the firewall's decision, not this resolver's. Once a
 * caller is authenticated, though, the tenant they claim must be the tenant
 * they actually belong to, unless they hold one of the privileged roles that
 * are explicitly allowed to act across tenants (support tooling, platform
 * admins). Anything else — a member of tenant A asking to act as tenant B —
 * is rejected outright rather than silently honoured or silently ignored.
 */
final readonly class MembershipVerifiedTenantResolver implements TenantResolverInterface
{
    /** @param list<string> $privilegedRoles */
    public function __construct(
        private TenantResolverInterface $inner,
        private array $privilegedRoles = ['ROLE_SUPER_ADMIN'],
    ) {}

    public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
    {
        $tenant = $this->inner->resolve($request, $user);
        if (null === $tenant) {
            return null;
        }

        if (null === $user) {
            return $tenant;
        }

        if ($this->isMember($user, $tenant->id) || $this->isPrivileged($user)) {
            return $tenant;
        }

        throw new AccessDeniedHttpException(sprintf(
            'Authenticated user "%s" is not a member of tenant "%s".',
            $user->getUserIdentifier(),
            $tenant->name ?? (string) $tenant->id,
        ));
    }

    private function isMember(UserInterface $user, int $tenantId): bool
    {
        return $user instanceof TenantAwareUserInterface && $user->getTenantId() === $tenantId;
    }

    private function isPrivileged(UserInterface $user): bool
    {
        $roles = array_map(strtoupper(...), $user->getRoles());

        return [] !== array_intersect($roles, array_map(strtoupper(...), $this->privilegedRoles));
    }
}
