<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Nubit\Platform\Tenant\Context\TenantContext;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default claims: roles, plus tenant id/name when a tenant is active.
 * Applications replace this service to enrich tokens and the auth response.
 */
final readonly class DefaultTokenClaimsProvider implements TokenClaimsProviderInterface
{
    public function __construct(
        private ?TenantContext $tenantContext = null,
    ) {
    }

    public function claims(UserInterface $user, array $previousClaims = []): array
    {
        return [
            'roles' => $user->getRoles(),
            'tenantId' => $this->tenantContext?->getTenantId(),
            'tenantName' => $this->tenantContext?->getTenantName(),
        ];
    }

    public function userData(UserInterface $user): array
    {
        return [
            'username' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ];
    }
}
