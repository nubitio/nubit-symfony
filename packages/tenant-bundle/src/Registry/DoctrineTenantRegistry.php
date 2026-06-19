<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Registry;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\Platform\Tenant\Contract\TenantDescriptorRegistryInterface;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Nubit\Platform\Tenant\Model\TenantDescriptor;

final readonly class DoctrineTenantRegistry implements TenantRegistryInterface, TenantDescriptorRegistryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $tenantEntityClass,
    ) {
    }

    public function getTenants(): array
    {
        return array_map(
            static fn (TenantDescriptor $tenant): array => $tenant->toArray(),
            $this->tenants(),
        );
    }

    public function getTenantByName(string $name): ?array
    {
        $tenant = $this->findByName($name);

        return $tenant?->toArray();
    }

    public function getTenantByDomain(string $domain): ?array
    {
        $tenant = $this->findByDomain($domain);

        return $tenant?->toArray();
    }

    public function tenants(): array
    {
        $repository = $this->entityManager->getRepository($this->tenantEntityClass);
        $rows = $repository->findAll();

        return array_map(fn (object $row): TenantDescriptor => $this->mapEntity($row), $rows);
    }

    public function findByName(string $name): ?TenantDescriptor
    {
        $repository = $this->entityManager->getRepository($this->tenantEntityClass);
        $row = $repository->findOneBy($this->nameCriteria($name));

        return $row instanceof object ? $this->mapEntity($row) : null;
    }

    public function findByDomain(string $domain): ?TenantDescriptor
    {
        $repository = $this->entityManager->getRepository($this->tenantEntityClass);
        $row = $repository->findOneBy(['primaryDomain' => $domain]);

        return $row instanceof object ? $this->mapEntity($row) : null;
    }

    /**
     * @return array<string, string>
     */
    private function nameCriteria(string $name): array
    {
        return method_exists($this->tenantEntityClass, 'getSlug')
            ? ['slug' => $name]
            : ['name' => $name];
    }

    private function mapEntity(object $entity): TenantDescriptor
    {
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;
        $name = method_exists($entity, 'getName') ? $entity->getName() : null;
        $slug = method_exists($entity, 'getSlug') ? $entity->getSlug() : null;
        $domain = method_exists($entity, 'getPrimaryDomain') ? $entity->getPrimaryDomain() : null;
        $plan = method_exists($entity, 'getPlan') ? $entity->getPlan() : null;
        $status = method_exists($entity, 'getStatus') ? $entity->getStatus() : null;

        return TenantDescriptor::fromArray([
            'id' => $id,
            'name' => $slug ?? $name ?? '',
            'primary_domain' => $domain,
            'plan' => $plan,
            'status' => $status,
        ]);
    }
}