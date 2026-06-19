<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Nubit\Platform\Quota\Contract\QuotaEnforcerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\TenantBundle\Quota\QuotaResourceRegistry;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final readonly class QuotaEnforcementListener
{
    public function __construct(
        private QuotaEnforcerInterface $quotaEnforcer,
        private TenantContext $tenantContext,
        private QuotaResourceRegistry $quotaResourceRegistry,
        private bool $enabled,
    ) {
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        if (!$this->enabled || null === $this->tenantContext->getTenantId()) {
            return;
        }

        $resource = $this->quotaResourceRegistry->resolve($args->getObject());
        if (null === $resource) {
            return;
        }

        $this->quotaEnforcer->enforce($resource);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        unset($args);
        $this->quotaEnforcer->releaseLocks();
    }

    public function onClear(OnClearEventArgs $args): void
    {
        unset($args);
        $this->quotaEnforcer->releaseLocks();
    }
}