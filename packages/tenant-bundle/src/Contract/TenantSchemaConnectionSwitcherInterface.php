<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

/** Switches PostgreSQL search_path using a resolved, positive tenant ID. */
interface TenantSchemaConnectionSwitcherInterface
{
    public function switchToTenantId(int $tenantId): void;

    public function resetSearchPath(): void;
}
