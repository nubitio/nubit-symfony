<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

use Nubit\ApiPlatform\Attribute\RowScoped;

/** Caches `#[RowScoped]` lookups; every query on a scoped resource asks. */
final class RowScopeRegistry
{
    /** @var array<class-string, RowScoped|null> */
    private array $cache = [];

    /** @param class-string $resourceClass */
    public function find(string $resourceClass): ?RowScoped
    {
        if (array_key_exists($resourceClass, $this->cache)) {
            return $this->cache[$resourceClass];
        }

        if (!class_exists($resourceClass)) {
            return $this->cache[$resourceClass] = null;
        }

        $attributes = (new \ReflectionClass($resourceClass))->getAttributes(RowScoped::class);

        return $this->cache[$resourceClass] = [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
