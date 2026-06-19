<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

/**
 * Resolves a tenant identifier to a database URL for {@code isolation: database}.
 */
interface TenantDatabaseUrlProviderInterface
{
    public function resolveDatabaseUrl(string $tenantName, ?int $tenantId = null): ?string;
}