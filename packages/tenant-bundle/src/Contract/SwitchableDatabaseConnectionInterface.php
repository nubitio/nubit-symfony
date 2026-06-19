<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

/**
 * Doctrine connection that can reconnect to a different database URL at runtime.
 *
 * Configure {@code wrapper_class: Nubit\TenantBundle\Doctrine\Connection\DynamicUrlConnection}
 * on the tenant connection when using {@code isolation: database}.
 */
interface SwitchableDatabaseConnectionInterface
{
    public function switchToUrl(string $databaseUrl): void;
}