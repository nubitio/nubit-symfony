<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Contract;

/**
 * Transitional tenant registry port.
 *
 * This keeps the current array-based API stable so existing ControlPlane
 * implementations can satisfy both App\Core and Nubit\Platform contracts while
 * typed consumers migrate to TenantDescriptorRegistryInterface.
 */
interface TenantRegistryInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTenants(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function getTenantByName(string $name): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function getTenantByDomain(string $domain): ?array;
}
