<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\EventListener\TenantStampListener;
use PHPUnit\Framework\TestCase;

final class TenantStampListenerTest extends TestCase
{
    public function testStampsTenantIdFromContext(): void
    {
        $context = new TenantContext();
        $context->setTenant(12, 'acme', null, null);

        $entity = new OwnedEntity();
        $listener = new TenantStampListener($context, $this->createMock(EntityManagerInterface::class), null);
        $listener->prePersist(new PrePersistEventArgs($entity, $this->createMock(EntityManagerInterface::class)));

        self::assertSame(12, $entity->getTenantId());
    }

    public function testDoesNotOverwriteExistingTenantId(): void
    {
        $context = new TenantContext();
        $context->setTenant(12, 'acme', null, null);

        $entity = new OwnedEntity();
        $entity->setTenantId(5);

        $listener = new TenantStampListener($context, $this->createMock(EntityManagerInterface::class), null);
        $listener->prePersist(new PrePersistEventArgs($entity, $this->createMock(EntityManagerInterface::class)));

        self::assertSame(5, $entity->getTenantId());
    }
}

final class OwnedEntity implements TenantOwnedInterface
{
    private ?int $tenantId = null;

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(?int $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }
}