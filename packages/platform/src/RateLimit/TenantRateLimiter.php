<?php

declare(strict_types=1);

namespace Nubit\Platform\RateLimit;

use Nubit\Platform\Tenant\Scope\TenantScope;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

final readonly class TenantRateLimiter
{
    private RateLimitPolicy $policy;
    private ClockInterface $clock;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        #[Autowire('%env(default:tenant_rate_limit:TENANT_RATE_LIMIT)%')]
        string $limit,
        #[Autowire('%env(default:tenant_rate_window:TENANT_RATE_WINDOW)%')]
        string $window,
        ?ClockInterface $clock = null,
        private ?TenantScope $tenantScope = null,
    ) {
        $this->policy = RateLimitPolicy::fromStrings($limit, $window);
        $this->clock = $clock ?? new NativeClock();
    }

    public function check(string $tenantName): RateLimitResult
    {
        if ($this->policy->disabled()) {
            return new RateLimitResult(allowed: true, limit: 0, remaining: 0, retryAfter: 0);
        }

        $timestamp = $this->clock->now()->getTimestamp();
        $cacheKey = $this->tenantScope?->rateLimitKey($tenantName, $this->policy->windowKey($timestamp))
            ?? TenantScope::rateLimitCacheKey($tenantName, $this->policy->windowKey($timestamp));

        $item = $this->cache->getItem($cacheKey);

        /** @var int $current */
        $current = $item->isHit() ? (int)$item->get() + 1 : 1;

        $item->set($current);
        $item->expiresAfter($this->policy->windowSeconds);
        $this->cache->save($item);

        $remaining = max(0, $this->policy->limitPerWindow - $current);
        $allowed = $current <= $this->policy->limitPerWindow;
        $retryAfter = $allowed ? 0 : $this->policy->retryAfter($timestamp);

        return new RateLimitResult(
            allowed: $allowed,
            limit: $this->policy->limitPerWindow,
            remaining: $remaining,
            retryAfter: $retryAfter,
        );
    }

}
