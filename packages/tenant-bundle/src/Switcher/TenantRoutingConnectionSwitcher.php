<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Switcher;

use Nubit\Platform\Exception\ServiceException;
use Nubit\Platform\Tenant\Contract\ResettableTenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantDescriptorRegistryInterface;
use Nubit\Platform\Tenant\Model\TenantDescriptor;
use Nubit\TenantBundle\Contract\TenantDatabaseConnectionSwitcherInterface;
use Nubit\TenantBundle\Contract\TenantIsolationTargetProviderInterface;
use Nubit\TenantBundle\Contract\TenantSchemaConnectionSwitcherInterface;
use Nubit\TenantBundle\Isolation\TenantIsolationTarget;

/** Routes non-HTTP tenant activation to the configured data-isolation target. */
final class TenantRoutingConnectionSwitcher implements TenantConnectionSwitcherInterface, ResettableTenantConnectionSwitcherInterface
{
    private ?string $activeTarget = null;

    public function __construct(
        private readonly string $isolation,
        private readonly TenantDescriptorRegistryInterface $tenantRegistry,
        private readonly ?TenantSchemaConnectionSwitcherInterface $schemaSwitcher = null,
        private readonly ?TenantIsolationTargetProviderInterface $targetProvider = null,
        private readonly ?TenantDatabaseConnectionSwitcherInterface $databaseSwitcher = null,
        private readonly ?TenantConnectionSwitcherInterface $columnSwitcher = null,
    ) {
        if (!in_array($isolation, ['schema', 'hybrid'], strict: true)) {
            throw new \InvalidArgumentException(sprintf('Tenant routing switcher does not support "%s" isolation.', $isolation));
        }
    }

    public function switchConnection(string $tenant): void
    {
        if ('' === trim($tenant)) {
            throw new ServiceException('Tenant connection switching requires a non-empty tenant name.');
        }

        $tenantDescriptor = $this->resolveTenant($tenant);

        if ('schema' === $this->isolation) {
            $this->switchToSchema($tenantDescriptor);

            return;
        }

        $target = $this->targetProvider?->resolveTarget($tenantDescriptor->name, $tenantDescriptor->id);
        if (null === $target) {
            throw new ServiceException(sprintf('No isolation target configured for tenant "%s".', $tenant));
        }

        match ($target->mode) {
            TenantIsolationTarget::COLUMN => $this->switchToColumn($tenant),
            TenantIsolationTarget::DATABASE => $this->switchToDatabase($target, $tenant),
            TenantIsolationTarget::SCHEMA => $this->switchToSchema($tenantDescriptor),
            default => throw new ServiceException(sprintf('Unsupported isolation target for tenant "%s".', $tenant)),
        };
    }

    public function resetConnection(): void
    {
        $activeTarget = $this->activeTarget;
        $this->activeTarget = null;

        if (TenantIsolationTarget::SCHEMA === $activeTarget) {
            if (null === $this->schemaSwitcher) {
                throw new ServiceException('Schema isolation requires a PostgreSQL schema connection switcher.');
            }

            $this->schemaSwitcher->resetSearchPath();

            return;
        }

        if (TenantIsolationTarget::DATABASE === $activeTarget) {
            $this->resetDatabaseConnection();
        }
    }

    private function resolveTenant(string $tenantName): TenantDescriptor
    {
        $tenant = $this->tenantRegistry->findByName($tenantName);
        if (null === $tenant) {
            throw new ServiceException(sprintf('No tenant descriptor found for "%s".', $tenantName));
        }

        if ($tenant->id <= 0) {
            throw new ServiceException(sprintf('Tenant "%s" does not have a valid tenant ID.', $tenantName));
        }

        return $tenant;
    }

    private function switchToSchema(TenantDescriptor $tenant): void
    {
        if (null === $this->schemaSwitcher) {
            throw new ServiceException('Schema isolation requires a PostgreSQL schema connection switcher.');
        }

        $this->schemaSwitcher->switchToTenantId($tenant->id);
        $this->activeTarget = TenantIsolationTarget::SCHEMA;
    }

    private function switchToColumn(string $tenantName): void
    {
        if (null === $this->columnSwitcher) {
            throw new ServiceException('Hybrid column isolation requires a column connection switcher.');
        }

        $this->columnSwitcher->switchConnection($tenantName);
    }

    private function switchToDatabase(TenantIsolationTarget $target, string $tenantName): void
    {
        if (null === $this->databaseSwitcher || !$this->databaseSwitcher instanceof ResettableTenantConnectionSwitcherInterface) {
            throw new ServiceException('Hybrid database isolation requires a resettable database connection switcher.');
        }

        if (null === $target->databaseUrl || '' === trim($target->databaseUrl)) {
            throw new ServiceException(sprintf('No database URL configured for tenant "%s".', $tenantName));
        }

        $this->databaseSwitcher->switchToDatabaseUrl($target->databaseUrl);
        $this->activeTarget = TenantIsolationTarget::DATABASE;
    }

    private function resetDatabaseConnection(): void
    {
        if (!$this->databaseSwitcher instanceof ResettableTenantConnectionSwitcherInterface) {
            throw new ServiceException('Hybrid database isolation requires a resettable database connection switcher.');
        }

        $this->databaseSwitcher->resetConnection();
    }
}
