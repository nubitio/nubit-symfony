<?php

declare(strict_types=1);

namespace Nubit\Platform\Notification\Contract;

use Nubit\Platform\Notification\NotificationMessage;

interface NotificationChannelInterface
{
    /** Stable identifier used in NotificationMessage::$channels, e.g. "email". */
    public function getIdentifier(): string;

    public function send(NotificationMessage $message): void;
}
