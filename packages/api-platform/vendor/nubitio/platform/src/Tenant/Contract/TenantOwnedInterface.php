<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Contract;

/**
 * Rows owned by a tenant.
 *
 * Lives in `nubitio/platform`, not `nubitio/tenant-bundle`, so a package can
 * declare its entities tenant-owned — and get a `tenant_id` column ready for
 * multi-tenant deployments — without requiring tenant-bundle at all. Reads
 * are scoped and writes are stamped only when tenant-bundle is installed and
 * configured; otherwise the column exists and simply stays unused, which is
 * what a single-tenant install already wants.
 *
 * @see \Nubit\TenantBundle\Contract\TenantOwnedInterface for the tenant-bundle
 *      alias kept for source compatibility — implement this one directly in
 *      new code.
 */
interface TenantOwnedInterface
{
    public function getTenantId(): ?int;

    public function setTenantId(?int $tenantId): static;
}
