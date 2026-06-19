<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

use Nubit\TenantBundle\Contract\TenantAwareUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
    {
        if (!$user instanceof TenantAwareUserInterface) {
            return null;
        }

        $tenantId = $user->getTenantId();
        if (null === $tenantId) {
            return null;
        }

        return new ResolvedTenant(
            $tenantId,
            $user->getTenantName(),
            $user->getTenantDomain(),
        );
    }
}