<?php

declare(strict_types=1);

namespace Nubit\Platform\RateLimit;

use InvalidArgumentException;

final readonly class RateLimitPolicy
{
    public function __construct(
        public int $limitPerWindow,
        public int $windowSeconds,
    ) {
        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('Rate limit window must be at least one second.');
        }
    }

    public static function fromStrings(string $limit, string $window): self
    {
        return new self((int) $limit, max(1, (int) $window));
    }

    public function disabled(): bool
    {
        return $this->limitPerWindow <= 0;
    }

    public function windowKey(int $timestamp): string
    {
        return (string) (int) floor($timestamp / $this->windowSeconds);
    }

    public function retryAfter(int $timestamp): int
    {
        return $this->windowSeconds - ($timestamp % $this->windowSeconds);
    }
}
