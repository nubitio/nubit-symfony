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
    /** @var array<string, mixed>|null */
    private ?array $baseParams = null;

    public function switchToUrl(string $databaseUrl): void
    {
        $this->baseParams ??= $this->getParams();

        $parser = new DsnParser([
            'postgresql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'mysql' => 'pdo_mysql',
        ]);

        $this->replaceParams(array_replace($this->getParams(), $parser->parse($databaseUrl)));
    }

    public function resetToBaseUrl(): void
    {
        if (null === $this->baseParams) {
            return;
        }

        $this->replaceParams($this->baseParams);
    }

    /** @param array<string, mixed> $params */
    private function replaceParams(array $params): void
    {
        $this->close();

        $reflection = new ReflectionClass(Connection::class);
        $paramsProperty = $reflection->getProperty('params');
        $paramsProperty->setValue($this, $params);

        $platformProperty = $reflection->getProperty('platform');
        $platformProperty->setValue($this, null);
    }
}
