<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Doctrine\Connection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\DsnParser;
use Nubit\TenantBundle\Contract\SwitchableDatabaseConnectionInterface;
use ReflectionClass;

/**
 * @internal Extend DBAL Connection so database-per-tenant apps can swap URLs per request.
 */
final class DynamicUrlConnection extends Connection implements SwitchableDatabaseConnectionInterface
{
    public function switchToUrl(string $databaseUrl): void
    {
        $this->close();

        $parser = new DsnParser([
            'postgresql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'mysql' => 'pdo_mysql',
        ]);

        $params = array_replace($this->getParams(), $parser->parse($databaseUrl));

        $reflection = new ReflectionClass(Connection::class);
        $paramsProperty = $reflection->getProperty('params');
        $paramsProperty->setValue($this, $params);

        $platformProperty = $reflection->getProperty('platform');
        $platformProperty->setValue($this, null);
    }
}