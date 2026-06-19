<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\TenantBundle\Attribute\TenantScoped;
use Nubit\TenantBundle\EventListener\TenantStampListener;
use PHPUnit\Framework\TestCase;

final class TenantRelationStampListenerTest extends TestCase
{
    public function testStampsConfiguredAssociationFromTenantContext(): void
    {
        $context = new TenantContext();
        $context->setTenant(9, 'acme', null, null);

        $restaurant = new RestaurantStub(9);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getReference')
            ->with(RestaurantStub::class, 9)
            ->willReturn($restaurant);

        $entity = new RelationOwnedEntity();
        $listener = new TenantStampListener($context, $entityManager, RestaurantStub::class);
        $listener->prePersist(new PrePersistEventArgs($entity, $entityManager));

        self::assertSame($restaurant, $entity->getRestaurant());
    }
}

#[TenantScoped(field: 'restaurant_id', relation: 'restaurant')]
final class RelationOwnedEntity
{
    private ?RestaurantStub $restaurant = null;

    public function getRestaurant(): ?RestaurantStub
    {
        return $this->restaurant;
    }

    public function setRestaurant(?RestaurantStub $restaurant): void
    {
        $this->restaurant = $restaurant;
    }
}

final class RestaurantStub
{
    public function __construct(private int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}