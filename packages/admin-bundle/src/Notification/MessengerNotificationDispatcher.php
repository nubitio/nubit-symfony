<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Notification;

use Nubit\Platform\Notification\Contract\NotificationDispatcherInterface;
use Nubit\Platform\Notification\NotificationMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches through Messenger rather than sending inline — a workflow
 * transition (or any request-cycle code) that triggers a notification
 * shouldn't block on (or fail because of) a slow/unreachable mail server.
 * Routes to sync/async like any other message: configure a transport for
 * NotificationMessage in the app's messenger.yaml to make it async.
 */
final readonly class MessengerNotificationDispatcher implements NotificationDispatcherInterface
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function dispatch(NotificationMessage $message): void
    {
        $this->bus->dispatch($message);
    }
}
