<?php

declare(strict_types=1);

namespace Nubit\Platform\RateLimit;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TenantRateLimiter
{
    private int $limitPerWindow;
    private int $windowSeconds;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        #[Autowire('%env(default:tenant_rate_limit:TENANT_RATE_LIMIT)%')]
        string $limit,
        #[Autowire('%env(default:tenant_rate_window:TENANT_RATE_WINDOW)%')]
        string $window,
    ) {
        $this->limitPerWindow = (int)$limit;
        $this->windowSeconds = (int)$window;
    }

    public function check(string $tenantName): RateLimitResult
    {
        if ($this->limitPerWindow <= 0) {
            return new RateLimitResult(allowed: true, limit: 0, remaining: 0, retryAfter: 0);
        }

        $windowKey = (string)(int)(time() / $this->windowSeconds);
        $cacheKey = 'tenant_rl.' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $tenantName) . '.' . $windowKey;

        $item = $this->cache->getItem($cacheKey);

        /** @var int $current */
        $current = $item->isHit() ? (int)$item->get() + 1 : 1;

        $item->set($current);
        $item->expiresAfter($this->windowSeconds);
        $this->cache->save($item);

        $remaining = max(0, $this->limitPerWindow - $current);
        $allowed = $current <= $this->limitPerWindow;
        $retryAfter = $allowed ? 0 : $this->windowSeconds - (time() % $this->windowSeconds);

        return new RateLimitResult(
            allowed: $allowed,
            limit: $this->limitPerWindow,
            remaining: $remaining,
            retryAfter: $retryAfter,
        );
    }
}
