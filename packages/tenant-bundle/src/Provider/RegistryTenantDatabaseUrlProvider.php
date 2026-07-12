<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\Platform\Exception\ServiceException;
use Nubit\TenantBundle\Contract\TenantDatabaseUrlProviderInterface;
use Nubit\TenantBundle\Contract\TenantIsolationTargetProviderInterface;
use Nubit\TenantBundle\Isolation\TenantIsolationTarget;

/**
 * Reads {@code databaseUrl} from the control-plane tenant entity (by slug or id).
 */
final readonly class RegistryTenantDatabaseUrlProvider implements TenantDatabaseUrlProviderInterface, TenantIsolationTargetProviderInterface
{
    public function __construct(
        private EntityManagerInterface $controlPlaneEntityManager,
        private string $tenantEntityClass,
    ) {
    }

    public function resolveDatabaseUrl(string $tenantName, ?int $tenantId = null): ?string
    {
        $tenant = $this->findTenant($tenantName, $tenantId);
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

    public function resolveTarget(string $tenantName, ?int $tenantId = null): ?TenantIsolationTarget
    {
        $tenant = $this->findTenant($tenantName, $tenantId);
        if (null === $tenant) {
            return null;
        }

        if (!method_exists($tenant, 'getIsolationMode')) {
            throw new ServiceException(sprintf(
                'Tenant entity "%s" must expose getIsolationMode() for hybrid isolation.',
                $this->tenantEntityClass,
            ));
        }

        $mode = $tenant->getIsolationMode();
        if (!is_string($mode)) {
            throw new ServiceException(sprintf('Tenant entity "%s" returned an invalid isolation mode.', $this->tenantEntityClass));
        }

        if (TenantIsolationTarget::SCHEMA === $mode) {
            throw new ServiceException(
                'Schema isolation requires an application-owned TenantIsolationTargetProviderInterface.',
            );
        }

        try {
            return new TenantIsolationTarget(
                $mode,
                TenantIsolationTarget::DATABASE === $mode ? $this->resolveDatabaseUrlFromTenant($tenant) : null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new ServiceException($exception->getMessage(), previous: $exception);
        }
    }

    private function findTenant(string $tenantName, ?int $tenantId): ?object
    {
        $repository = $this->controlPlaneEntityManager->getRepository($this->tenantEntityClass);
        $tenant = null !== $tenantId ? $repository->find($tenantId) : null;

        if (null === $tenant && '' !== $tenantName) {
            $tenant = $repository->findOneBy(['slug' => $tenantName])
                ?? $repository->findOneBy(['name' => $tenantName]);
        }

        return $tenant;
    }

    private function resolveDatabaseUrlFromTenant(object $tenant): ?string
    {
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
