<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AnalyticsEvent
{
    public function __construct(
        public string $id,
        public string $name,
        public int $schemaVersion,
        public AnalyticsPurpose $purpose,
        public object $payload,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.]{2,99}$/', $name)) {
            throw new InvalidArgumentException(
                'Analytics event name must be 3-100 lowercase letters, digits, dots or underscores.',
            );
        }
        if ('' === trim($id) || strlen($id) > 128) {
            throw new InvalidArgumentException('Analytics event ID must be non-empty and at most 128 bytes.');
        }
        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Analytics event schema version must be positive.');
        }
    }
}
