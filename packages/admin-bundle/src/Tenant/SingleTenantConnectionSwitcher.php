<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tenant;

use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;

/** Single-tenant default: one database, nothing to switch. */
final class SingleTenantConnectionSwitcher implements TenantConnectionSwitcherInterface
{
    public function switchConnection(string $tenant): void
    {
        // Single database — nothing to switch.
    }
}
