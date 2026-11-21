<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Contract;

interface TenantConnectionSwitcherInterface
{
    public function switchConnection(string $tenant): void;
}
