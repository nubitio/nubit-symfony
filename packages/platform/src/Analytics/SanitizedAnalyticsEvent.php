<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics;

use DateTimeImmutable;

final readonly class SanitizedAnalyticsEvent
{
    /** @param array<string, mixed> $properties */
    public function __construct(
        public string $id,
        public string $name,
        public int $schemaVersion,
        public AnalyticsPurpose $purpose,
        public array $properties,
        public DateTimeImmutable $occurredAt,
        public ?int $tenantId,
    ) {}
}
