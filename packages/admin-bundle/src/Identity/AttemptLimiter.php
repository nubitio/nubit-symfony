<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * A fixed-window counter for credential flows.
 *
 * Separate from {@see \Nubit\Platform\RateLimit\TenantRateLimiter}, which
 * counts per tenant. Here the key is the thing being attacked — an email
 * address, an IP — and both are counted at once: limiting only by identity lets
 * one host walk a list of addresses, and limiting only by IP lets a botnet
 * hammer a single account.
 */
final readonly class AttemptLimiter
{
    private ClockInterface $clock;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $limit = 5,
        private int $windowSeconds = 900,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /** Counts one attempt against every key, and reports whether they are all still under the limit. */
    public function allow(string ...$keys): bool
    {
        if ($this->limit <= 0) {
            return true;
        }

        $window = intdiv($this->clock->now()->getTimestamp(), $this->windowSeconds);

        // Every key is incremented even once one has already failed: an
        // attacker must not be able to keep a counter from rising by making
        // sure some other one trips first.
        $allowed = true;
        foreach ($keys as $key) {
            if ('' === $key) {
                continue;
            }

            $item = $this->cache->getItem(self::cacheKey($key, $window));
            $count = ($item->isHit() ? (int) $item->get() : 0) + 1;

            $item->set($count);
            $item->expiresAfter($this->windowSeconds);
            $this->cache->save($item);

            if ($count > $this->limit) {
                $allowed = false;
            }
        }

        return $allowed;
    }

    public function reset(string ...$keys): void
    {
        $window = intdiv($this->clock->now()->getTimestamp(), $this->windowSeconds);

        foreach ($keys as $key) {
            if ('' !== $key) {
                $this->cache->deleteItem(self::cacheKey($key, $window));
            }
        }
    }

    private static function cacheKey(string $key, int $window): string
    {
        // Hashed: an email address must not become a cache key somebody can
        // read off a shared Redis instance.
        return sprintf('nubit_identity_attempt_%s_%d', hash('sha256', $key), $window);
    }
}
