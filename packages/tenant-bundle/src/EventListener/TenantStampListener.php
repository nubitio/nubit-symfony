<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Doctrine\TenantScopedMetadata;

#[AsDoctrineListener(event: Events::prePersist)]
final class TenantStampListener
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?string $tenantEntityClass,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $metadata = new TenantScopedMetadata($this->tenantEntityClass);

        if (!$metadata->shouldStamp($entity::class)) {
            return;
        }

        $relation = $metadata->resolveRelation($entity::class);
        if (null !== $relation && null !== $this->tenantEntityClass) {
            $this->stampRelation($entity, $relation);

            return;
        }

        if (!$entity instanceof TenantOwnedInterface || null !== $entity->getTenantId()) {
            return;
        }

        $tenantId = $this->tenantContext->getTenantId();
        if (null === $tenantId) {
            return;
        }

        $entity->setTenantId($tenantId);
    }

    private function stampRelation(object $entity, string $relation): void
    {
        $getter = 'get'.ucfirst($relation);
        $setter = 'set'.ucfirst($relation);

        if (!method_exists($entity, $getter) || !method_exists($entity, $setter)) {
            return;
        }

        if (null !== $entity->$getter()) {
            return;
        }

        $tenantId = $this->tenantContext->getTenantId();
        if (null === $tenantId || null === $this->tenantEntityClass) {
            return;
        }

        $reference = $this->entityManager->getReference($this->tenantEntityClass, $tenantId);
        $entity->$setter($reference);
    }
}