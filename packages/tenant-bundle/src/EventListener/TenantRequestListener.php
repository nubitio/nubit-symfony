<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\TenantBundle\Doctrine\Filter\TenantFilter;
use Nubit\TenantBundle\Resolver\TenantResolverInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class TenantRequestListener
{
    public function __construct(
        private readonly TenantResolverInterface $tenantResolver,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly TenantConnectionSwitcherInterface $connectionSwitcher,
        private readonly string $isolation,
        private readonly bool $rlsEnabled,
        private readonly ?string $tenantEntityClass,
    ) {
    }

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

        if ('database' === $this->isolation) {
            $this->connectionSwitcher->switchConnection($resolved->name);

            return;
        }

        $filters = $this->entityManager->getFilters();
        if (!$filters->isEnabled(TenantFilter::NAME)) {
            $filter = $filters->enable(TenantFilter::NAME);
            $filter->setParameter(TenantFilter::PARAMETER, $resolved->id, 'integer');
            if (null !== $this->tenantEntityClass) {
                $filter->setParameter(TenantFilter::TENANT_ENTITY_PARAMETER, $this->tenantEntityClass, 'string');
            }
        }

        if ($this->rlsEnabled) {
            $this->applyRls($this->entityManager->getConnection(), $resolved->id);
        }
    }

    private function applyRls(Connection $connection, int $tenantId): void
    {
        $connection->executeStatement(
            "SELECT set_config('app.tenant_id', ?, true)",
            [(string) $tenantId],
        );
    }
}