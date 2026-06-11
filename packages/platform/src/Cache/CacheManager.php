<?php

declare(strict_types=1);

namespace Nubit\Platform\Cache;

use Nubit\Platform\Tenant\Context\TenantContext;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

readonly class CacheManager
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private TenantContext $tenantContext,
    ) {
    }

    /** @throws InvalidArgumentException */
    public function get(string $key): mixed
    {
        $item = $this->cache->getItem($this->scopedKey($key));

        if (!$item->isHit()) {
            return null;
        }

        return $item->get();
    }

    /** @throws InvalidArgumentException */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $item = $this->cache->getItem($this->scopedKey($key));
        $item->set($value);

        if (null !== $ttl) {
            $item->expiresAfter($ttl);
        }

        $this->cache->save($item);
    }

    /** @throws InvalidArgumentException */
    public function has(string $key): bool
    {
        return $this->cache->hasItem($this->scopedKey($key));
    }

    /** @throws InvalidArgumentException */
    public function delete(string $key): void
    {
        $this->cache->deleteItem($this->scopedKey($key));
    }

    /** @throws InvalidArgumentException */
    public function clear(): void
    {
        $this->cache->clear();
    }

    /** @throws InvalidArgumentException */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /** @throws InvalidArgumentException */
    public function forget(string $key): void
    {
        $this->delete($key);
    }

    private function scopedKey(string $key): string
    {
        $tenantName = $this->tenantContext->getTenantName();

        if ($tenantName === null) {
            return $key;
        }

        return "t.{$tenantName}.{$key}";
    }
}
