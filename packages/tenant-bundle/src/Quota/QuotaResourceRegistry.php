<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Quota;

use Nubit\TenantBundle\Attribute\QuotaResource;

final class QuotaResourceRegistry
{
    /** @var array<class-string, string> */
    private array $cache = [];

    public function resolve(object $entity): ?string
    {
        $class = $entity::class;

        if (!isset($this->cache[$class])) {
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(QuotaResource::class);
            $this->cache[$class] = $attributes === []
                ? ''
                : $attributes[0]->newInstance()->resource;
        }

        $resource = $this->cache[$class];

        return '' === $resource ? null : $resource;
    }
}