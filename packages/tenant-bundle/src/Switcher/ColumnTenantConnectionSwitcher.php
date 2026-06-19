<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Switcher;

use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;

/**
 * Column isolation does not switch database connections. Per-tenant console
 * commands still call this port — the implementation is intentionally a no-op.
 */
final class ColumnTenantConnectionSwitcher implements TenantConnectionSwitcherInterface
{
    public function switchConnection(string $tenantName): void
    {
    }
}