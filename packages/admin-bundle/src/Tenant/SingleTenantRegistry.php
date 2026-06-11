<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tenant;

use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;

/**
 * Single-tenant default: no named tenants. Multi-tenant applications
 * override this binding in their services.yaml.
 */
final class SingleTenantRegistry implements TenantRegistryInterface
{
    public function getTenants(): array
    {
        return [];
    }

    public function getTenantByName(string $name): ?array
    {
        return null;
    }

    public function getTenantByDomain(string $domain): ?array
    {
        return null;
    }
}
