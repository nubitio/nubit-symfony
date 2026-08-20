<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class SubdomainTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRegistryInterface $tenantRegistry,
        private string $baseDomain = '',
    ) {
    }

    public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
    {
        $host = $request->getHost();
        if ('' === $host) {
            return null;
        }

        $slug = $this->extractSlug($host);
        $tenant = null !== $slug ? $this->tenantRegistry->getTenantByName($slug) : null;
        $tenant ??= $this->tenantRegistry->getTenantByDomain($host);

        return null === $tenant ? null : ResolvedTenant::fromRegistryRecord($tenant, $host);
    }

    private function extractSlug(string $host): ?string
    {
        if ('' !== $this->baseDomain && str_ends_with($host, '.'.$this->baseDomain)) {
            $slug = substr($host, offset: 0, length: -strlen('.'.$this->baseDomain));

            return '' !== $slug ? $slug : null;
        }

        $parts = explode('.', $host);
        if (count($parts) < 3) {
            return null;
        }

        if ('www' !== $parts[0]) {
            return $parts[0];
        }

        return $parts[1] ?? null;
    }
}
