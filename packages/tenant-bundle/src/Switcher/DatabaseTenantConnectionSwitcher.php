<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Switcher;

use Doctrine\Persistence\ConnectionRegistry;
use Nubit\Platform\Exception\ServiceException;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\TenantBundle\Contract\SwitchableDatabaseConnectionInterface;
use Nubit\TenantBundle\Contract\TenantDatabaseUrlProviderInterface;

final readonly class DatabaseTenantConnectionSwitcher implements TenantConnectionSwitcherInterface
{
    public function __construct(
        private ConnectionRegistry $connectionRegistry,
        private TenantDatabaseUrlProviderInterface $databaseUrlProvider,
        private string $tenantConnectionName = 'default',
    ) {
    }

    public function switchConnection(string $tenant): void
    {
        $databaseUrl = $this->databaseUrlProvider->resolveDatabaseUrl($tenant);
        if (null === $databaseUrl) {
            throw new ServiceException(sprintf('No database URL configured for tenant "%s".', $tenant));
        }

        $connection = $this->connectionRegistry->getConnection($this->tenantConnectionName);
        if (!$connection instanceof SwitchableDatabaseConnectionInterface) {
            throw new ServiceException(sprintf(
                'Connection "%s" must use wrapper_class %s for database isolation.',
                $this->tenantConnectionName,
                SwitchableDatabaseConnectionInterface::class,
            ));
        }

        $connection->switchToUrl($databaseUrl);
    }
}