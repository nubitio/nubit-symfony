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
        if (null === $slug) {
            return null;
        }

        $tenant = $this->tenantRegistry->getTenantByName($slug)
            ?? $this->tenantRegistry->getTenantByDomain($host);

        if (null === $tenant || !isset($tenant['id'])) {
            return null;
        }

        return new ResolvedTenant(
            (int) $tenant['id'],
            isset($tenant['name']) ? (string) $tenant['name'] : null,
            isset($tenant['primary_domain']) ? (string) $tenant['primary_domain'] : ($host !== $slug ? $host : null),
        );
    }

    private function extractSlug(string $host): ?string
    {
        if ('' !== $this->baseDomain && str_ends_with($host, '.'.$this->baseDomain)) {
            $slug = substr($host, 0, -strlen('.'.$this->baseDomain));

            return '' !== $slug ? $slug : null;
        }

        $parts = explode('.', $host);
        if (count($parts) < 3) {
            return null;
        }

        return $parts[0] !== 'www' ? $parts[0] : ($parts[1] ?? null);
    }
}