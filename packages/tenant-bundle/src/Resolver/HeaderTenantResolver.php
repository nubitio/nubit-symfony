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
        $raw = trim((string) $request->headers->get($this->header, ''));
        if ('' === $raw) {
            return null;
        }

        if (ctype_digit($raw) && (int) $raw > 0) {
            return new ResolvedTenant((int) $raw);
        }

        $tenant = $this->tenantRegistry->getTenantByName($raw);
        return null === $tenant ? null : ResolvedTenant::fromRegistryRecord($tenant);
    }
}
