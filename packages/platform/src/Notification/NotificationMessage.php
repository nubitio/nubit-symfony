<?php

declare(strict_types=1);

namespace Nubit\Platform\Notification;

/**
 * Channel-agnostic notification payload. Kept to scalar/array properties
 * (no objects) so it survives Messenger serialization unchanged when
 * dispatched asynchronously.
 */
final readonly class NotificationMessage
{
    /**
     * @param list<string> $channels Channel identifiers to attempt, e.g. ['email']. Empty means "every registered channel".
     * @param array<string, mixed> $context Template/rendering data available to channels (e.g. an email template).
     */
    public function __construct(
        public string $recipient,
        public string $subject,
        public string $body,
        public array $channels = [],
        public array $context = [],
    ) {
    }
}
