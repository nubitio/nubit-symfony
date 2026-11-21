<?php

declare(strict_types=1);

namespace Nubit\Platform\Notification\Contract;

use Nubit\Platform\Notification\NotificationMessage;

/** Entry point domain code calls (e.g. from a workflow transition listener) to send a notification. */
interface NotificationDispatcherInterface
{
    public function dispatch(NotificationMessage $message): void;
}
