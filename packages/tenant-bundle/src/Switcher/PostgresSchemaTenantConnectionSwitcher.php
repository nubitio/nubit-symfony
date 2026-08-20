<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Switcher;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use Nubit\Platform\Exception\ServiceException;
use Nubit\Platform\Tenant\Contract\ResettableTenantConnectionSwitcherInterface;
use Nubit\TenantBundle\Contract\TenantSchemaConnectionSwitcherInterface;

/** Safely scopes a PostgreSQL connection through an explicitly configured search path. */
final readonly class PostgresSchemaTenantConnectionSwitcher implements TenantSchemaConnectionSwitcherInterface, ResettableTenantConnectionSwitcherInterface
{
    /** @var list<string> */
    private array $baseSchemas;

    /** @param list<string> $baseSchemas */
    public function __construct(
        private ConnectionRegistry $connectionRegistry,
        private string $tenantConnectionName = 'default',
        private string $schemaPrefix = 'tenant_',
        array $baseSchemas = ['public'],
    ) {
        self::assertIdentifier($schemaPrefix, 'Schema prefix');
        if ([] === $baseSchemas) {
            throw new \InvalidArgumentException('At least one base schema must be configured.');
        }
        foreach ($baseSchemas as $schema) {
            self::assertIdentifier($schema, 'Base schema');
        }
        $this->baseSchemas = array_values($baseSchemas);
    }

    public function switchToTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Schema tenant isolation requires a positive resolved tenant ID.');
        }

        $schema = $this->schemaForTenantId($tenantId);
        $connection = $this->connection();
        $this->assertNoActiveTransaction($connection->isTransactionActive());
        $connection->executeStatement(sprintf(
            'SET search_path TO %s',
            implode(', ', [self::quoteIdentifier($schema), ...array_map(self::quoteIdentifier(...), $this->baseSchemas)]),
        ));
    }

    public function resetSearchPath(): void
    {
        $connection = $this->connection();
        $this->assertNoActiveTransaction($connection->isTransactionActive());
        $connection->executeStatement(sprintf(
            'SET search_path TO %s',
            implode(', ', array_map(self::quoteIdentifier(...), $this->baseSchemas)),
        ));
    }

    public function resetConnection(): void
    {
        $this->resetSearchPath();
    }

    public function schemaForTenantId(int $tenantId): string
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Schema tenant isolation requires a positive resolved tenant ID.');
        }

        $schema = $this->schemaPrefix . $tenantId;
        self::assertIdentifier($schema, 'Tenant schema');

        return $schema;
    }

    private function connection(): Connection
    {
        $connection = $this->connectionRegistry->getConnection($this->tenantConnectionName);
        if (!$connection instanceof Connection) {
            throw new ServiceException(sprintf('Connection "%s" must support Doctrine DBAL search_path switching.', $this->tenantConnectionName));
        }

        return $connection;
    }

    private function assertNoActiveTransaction(bool $active): void
    {
        if ($active) {
            throw new ServiceException('Cannot switch or reset PostgreSQL schema search_path during an active transaction.');
        }
    }

    private static function assertIdentifier(string $identifier, string $label): void
    {
        if ('' === $identifier || strlen($identifier) > 63 || !preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException(sprintf('%s must be a lowercase PostgreSQL identifier no longer than 63 bytes.', $label));
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
