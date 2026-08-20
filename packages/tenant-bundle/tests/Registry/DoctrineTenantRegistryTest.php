<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Registry;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nubit\TenantBundle\Registry\DoctrineTenantRegistry;
use PHPUnit\Framework\TestCase;

final class DoctrineTenantRegistryTest extends TestCase
{
    public function testFindByNameMapsAnEntityReturnedByDoctrine(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'acme'])
            ->willReturn(new TenantEntityFixture(42, 'Acme Inc.', 'acme'));

        $registry = $this->registry($repository);
        $tenant = $registry->findByName('acme');

        self::assertNotNull($tenant);
        self::assertSame(42, $tenant->id);
        self::assertSame('acme', $tenant->name);
    }

    public function testFindByNameReturnsNullWhenDoctrineFindsNothing(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        self::assertNull($this->registry($repository)->findByName('missing'));
    }

    private function registry(EntityRepository $repository): DoctrineTenantRegistry
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new DoctrineTenantRegistry($entityManager, TenantEntityFixture::class);
    }
}

final readonly class TenantEntityFixture
{
    public function __construct(
        private int $id,
        private string $name,
        private string $slug,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }
}
