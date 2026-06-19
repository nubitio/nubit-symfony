<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\TenantBundle\Contract\TenantDatabaseUrlProviderInterface;
use Nubit\Platform\Exception\ServiceException;

/**
 * Reads {@code databaseUrl} from the control-plane tenant entity (by slug or id).
 */
final readonly class RegistryTenantDatabaseUrlProvider implements TenantDatabaseUrlProviderInterface
{
    public function __construct(
        private EntityManagerInterface $controlPlaneEntityManager,
        private string $tenantEntityClass,
    ) {
    }

    public function resolveDatabaseUrl(string $tenantName, ?int $tenantId = null): ?string
    {
        $repository = $this->controlPlaneEntityManager->getRepository($this->tenantEntityClass);

        $tenant = null;
        if (null !== $tenantId) {
            $tenant = $repository->find($tenantId);
        }

        if (null === $tenant && '' !== $tenantName) {
            $tenant = $repository->findOneBy(['slug' => $tenantName])
                ?? $repository->findOneBy(['name' => $tenantName]);
        }

        if (null === $tenant) {
            return null;
        }

        if (!method_exists($tenant, 'getDatabaseUrl')) {
            throw new ServiceException(sprintf(
                'Tenant entity "%s" must expose getDatabaseUrl() for database isolation.',
                $this->tenantEntityClass,
            ));
        }

        $url = $tenant->getDatabaseUrl();

        return is_string($url) && '' !== $url ? $url : null;
    }
}