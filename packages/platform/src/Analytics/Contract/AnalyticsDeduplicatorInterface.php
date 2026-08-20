<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics\Contract;

interface AnalyticsDeduplicatorInterface
{
    /** Atomically claims an event ID. Returns false when it was already claimed. */
    public function claim(string $eventId): bool;
}
