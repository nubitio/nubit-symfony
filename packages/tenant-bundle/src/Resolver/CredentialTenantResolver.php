<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

use Nubit\Platform\Tenant\Http\TenantCredentialRequestAttribute;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves the tenant a stateless credential (an API key, a service token)
 * was issued for.
 *
 * The value comes from a request attribute an authenticator sets after
 * verifying the credential — it is server-derived, the same way a JWT claim
 * is, so unlike {@see HeaderTenantResolver} or {@see SubdomainTenantResolver}
 * it needs no {@see MembershipVerifiedTenantResolver} wrapping: a caller
 * cannot set this attribute by sending a header, only by presenting a
 * credential the server already validated.
 */
final readonly class CredentialTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
    {
        if (!$request->attributes->has(TenantCredentialRequestAttribute::NAME)) {
            return null;
        }

        $tenantId = $request->attributes->get(TenantCredentialRequestAttribute::NAME);

        return is_int($tenantId) ? new ResolvedTenant($tenantId) : null;
    }
}
