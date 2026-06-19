<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class HeaderTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
        private string $header = 'X-Tenant-Id',
    ) {
    }

    public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
    {
        $raw = $request->headers->get($this->header);
        if (null === $raw || '' === trim($raw)) {
            return null;
        }

        if (ctype_digit($raw)) {
            return new ResolvedTenant((int) $raw);
        }

        $tenant = $this->tenantRegistry->getTenantByName($raw);
        if (null === $tenant || !isset($tenant['id'])) {
            return null;
        }

        return new ResolvedTenant(
            (int) $tenant['id'],
            isset($tenant['name']) ? (string) $tenant['name'] : null,
            isset($tenant['primary_domain']) ? (string) $tenant['primary_domain'] : null,
        );
    }
}