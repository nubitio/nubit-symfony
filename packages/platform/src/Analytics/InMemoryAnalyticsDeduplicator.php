<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics;

use InvalidArgumentException;
use Nubit\Platform\Analytics\Contract\AnalyticsDeduplicatorInterface;
use SplQueue;

final class InMemoryAnalyticsDeduplicator implements AnalyticsDeduplicatorInterface
{
    /** @var array<string, true> */
    private array $claimed = [];
    /** @var SplQueue<string> */
    private SplQueue $order;

    public function __construct(
        private readonly int $capacity = 10_000,
    ) {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Analytics deduplication capacity must be positive.');
        }
        $this->order = new SplQueue();
    }

    public function claim(string $eventId): bool
    {
        if (isset($this->claimed[$eventId])) {
            return false;
        }

        if (count($this->claimed) >= $this->capacity) {
            $oldest = $this->order->dequeue();
            unset($this->claimed[$oldest]);
        }

        $this->claimed[$eventId] = true;
        $this->order->enqueue($eventId);

        return true;
    }
}
