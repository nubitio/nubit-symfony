<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

/** Switches the configured tenant connection to an already-resolved database URL. */
interface TenantDatabaseConnectionSwitcherInterface
{
    public function switchToDatabaseUrl(string $databaseUrl): void;
}
