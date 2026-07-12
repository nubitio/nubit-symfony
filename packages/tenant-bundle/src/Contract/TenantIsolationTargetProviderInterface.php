<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

use Nubit\TenantBundle\Isolation\TenantIsolationTarget;

/** Resolves the placement target for a tenant from the control plane. */
interface TenantIsolationTargetProviderInterface
{
    public function resolveTarget(string $tenantName, ?int $tenantId = null): ?TenantIsolationTarget;
}
