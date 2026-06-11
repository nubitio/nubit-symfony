<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tenant;

use Nubit\Platform\Quota\Contract\QuotaEnforcerInterface;

/** Single-tenant default: no quotas enforced. */
final class UnlimitedQuotaEnforcer implements QuotaEnforcerInterface
{
    public function enforce(string $resource): void
    {
        // No quotas in single-tenant mode.
    }

    public function releaseLocks(): void
    {
    }
}
