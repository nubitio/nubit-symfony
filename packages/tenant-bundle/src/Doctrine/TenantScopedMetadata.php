<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Doctrine;

use Nubit\TenantBundle\Attribute\TenantScoped;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use ReflectionClass;

final readonly class TenantScopedMetadata
{
    public function __construct(
        private ?string $tenantEntityClass,
        /** @var list<class-string> */
        private array $unscopedEntityClasses = [],
    ) {
    }

    public function isGloballyUnscoped(string $entityClass): bool
    {
        return in_array($entityClass, $this->unscopedEntityClasses, true);
    }

    public function isTenantRoot(string $entityClass): bool
    {
        return null !== $this->tenantEntityClass && $entityClass === $this->tenantEntityClass;
    }

    public function resolveField(string $entityClass): ?string
    {
        if ($this->isTenantRoot($entityClass)) {
            return null;
        }

        $attribute = $this->readAttribute($entityClass);
        if (null !== $attribute) {
            return $attribute->field;
        }

        if (is_subclass_of($entityClass, TenantOwnedInterface::class)) {
            return 'tenant_id';
        }

        return null;
    }

    public function shouldStamp(string $entityClass): bool
    {
        $attribute = $this->readAttribute($entityClass);

        return $attribute?->stampOnPersist ?? is_subclass_of($entityClass, TenantOwnedInterface::class);
    }

    public function resolveRelation(string $entityClass): ?string
    {
        return $this->readAttribute($entityClass)?->relation;
    }

    private function readAttribute(string $entityClass): ?TenantScoped
    {
        if (!class_exists($entityClass)) {
            return null;
        }

        $reflection = new ReflectionClass($entityClass);
        $attributes = $reflection->getAttributes(TenantScoped::class);

        return $attributes !== [] ? $attributes[0]->newInstance() : null;
    }
}
