<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Contract;

/** An optional capability for connections that retain tenant-specific state. */
interface ResettableTenantConnectionSwitcherInterface
{
    public function resetConnection(): void;
}
