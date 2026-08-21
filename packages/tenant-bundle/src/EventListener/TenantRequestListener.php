<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\ResettableTenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\TenantBundle\Contract\TenantDatabaseConnectionSwitcherInterface;
use Nubit\TenantBundle\Contract\TenantIsolationTargetProviderInterface;
use Nubit\TenantBundle\Contract\TenantSchemaConnectionSwitcherInterface;
use Nubit\TenantBundle\Doctrine\Filter\TenantFilter;
use Nubit\TenantBundle\Isolation\TenantIsolationTarget;
use Nubit\TenantBundle\Resolver\TenantResolverInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
final class TenantRequestListener
{
    private ?string $activeConnectionTarget = null;

    public function __construct(
        private readonly TenantResolverInterface $tenantResolver,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly TenantConnectionSwitcherInterface $connectionSwitcher,
        private readonly string $isolation,
        private readonly bool $rlsEnabled,
        private readonly ?string $tenantEntityClass,
        /** @var list<class-string> */
        private readonly array $unscopedEntityClasses,
        private readonly ?TenantIsolationTargetProviderInterface $targetProvider = null,
        private readonly ?TenantDatabaseConnectionSwitcherInterface $databaseConnectionSwitcher = null,
        private readonly ?TenantSchemaConnectionSwitcherInterface $schemaConnectionSwitcher = null,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $user = $this->security->getUser();
        $resolved = $this->tenantResolver->resolve($request, $user);
        if (null === $resolved) {
            return;
        }

        $this->tenantContext->setTenant(
            $resolved->id,
            $resolved->name,
            $resolved->domain,
            $request->headers->get('X-Request-Id'),
        );

        $target = 'hybrid' === $this->isolation
            ? $this->resolveHybridTarget($resolved->name ?? '', $resolved->id)
            : null;

        if ('database' === $this->isolation) {
            if (null === $resolved->name || '' === trim($resolved->name)) {
                throw new \LogicException('Database isolation requires a non-empty tenant name.');
            }

            $this->connectionSwitcher->switchConnection($resolved->name);
            $this->activeConnectionTarget = TenantIsolationTarget::DATABASE;

            return;
        }

        if ('schema' === $this->isolation) {
            $this->switchToSchema($resolved->id);

            return;
        }

        if (null !== $target && TenantIsolationTarget::DATABASE === $target->mode) {
            if (null === $this->databaseConnectionSwitcher) {
                throw new \LogicException('Hybrid database isolation requires a database connection switcher.');
            }

            $databaseUrl = $target->databaseUrl;
            if (null === $databaseUrl || '' === trim($databaseUrl)) {
                throw new \LogicException('Hybrid database isolation requires a non-empty database URL.');
            }

            $this->databaseConnectionSwitcher->switchToDatabaseUrl($databaseUrl);
            $this->activeConnectionTarget = TenantIsolationTarget::DATABASE;

            return;
        }

        if (null !== $target && TenantIsolationTarget::SCHEMA === $target->mode) {
            $this->switchToSchema($resolved->id);

            return;
        }

        $filters = $this->entityManager->getFilters();
        $filter = $filters->isEnabled(TenantFilter::NAME)
            ? $filters->getFilter(TenantFilter::NAME)
            : $filters->enable(TenantFilter::NAME);
        $filter->setParameter(TenantFilter::PARAMETER, $resolved->id, 'integer');
        if (null !== $this->tenantEntityClass) {
            $filter->setParameter(TenantFilter::TENANT_ENTITY_PARAMETER, $this->tenantEntityClass, 'string');
        }
        $filter->setParameter(
            TenantFilter::UNSCOPED_ENTITIES_PARAMETER,
            implode(',', $this->unscopedEntityClasses),
            'string',
        );

        if ($this->rlsEnabled) {
            $this->applyRls($this->entityManager->getConnection(), $resolved->id);
        }
    }

    public function onResponse(\Symfony\Component\HttpKernel\Event\ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->activeConnectionTarget) {
            return;
        }

        try {
            if (
                TenantIsolationTarget::SCHEMA === $this->activeConnectionTarget
                && $this->schemaConnectionSwitcher instanceof TenantSchemaConnectionSwitcherInterface
            ) {
                $this->schemaConnectionSwitcher->resetSearchPath();
            } elseif (
                TenantIsolationTarget::DATABASE === $this->activeConnectionTarget
                && $this->databaseConnectionSwitcher instanceof ResettableTenantConnectionSwitcherInterface
            ) {
                $this->databaseConnectionSwitcher->resetConnection();
            } elseif (
                TenantIsolationTarget::DATABASE === $this->activeConnectionTarget
                && $this->connectionSwitcher instanceof ResettableTenantConnectionSwitcherInterface
            ) {
                $this->connectionSwitcher->resetConnection();
            }
        } finally {
            $this->activeConnectionTarget = null;
            $this->tenantContext->clear();
        }
    }

    private function applyRls(Connection $connection, int $tenantId): void
    {
        $connection->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]);
    }

    private function resolveHybridTarget(string $tenantName, int $tenantId): TenantIsolationTarget
    {
        if (null === $this->targetProvider) {
            throw new \LogicException('Hybrid isolation requires a tenant isolation target provider.');
        }

        $target = $this->targetProvider->resolveTarget($tenantName, $tenantId);
        if (null === $target) {
            throw new \LogicException(sprintf('No isolation target configured for tenant "%s".', $tenantName));
        }

        return $target;
    }

    private function switchToSchema(int $tenantId): void
    {
        if (null === $this->schemaConnectionSwitcher) {
            throw new \LogicException('Schema isolation requires a PostgreSQL schema connection switcher.');
        }

        $this->schemaConnectionSwitcher->switchToTenantId($tenantId);
        $this->activeConnectionTarget = TenantIsolationTarget::SCHEMA;
    }
}
