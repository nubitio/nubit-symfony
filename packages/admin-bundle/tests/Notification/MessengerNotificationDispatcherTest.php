<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Notification;

use Nubit\AdminBundle\Notification\MessengerNotificationDispatcher;
use Nubit\Platform\Notification\NotificationMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerNotificationDispatcherTest extends TestCase
{
    public function testDispatchesTheMessageThroughTheBusUnchanged(): void
    {
        $message = new NotificationMessage('ops@acme.test', 'Subject', 'Body');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(static::once())->method('dispatch')->with($message)->willReturn(new Envelope($message));

        (new MessengerNotificationDispatcher($bus))->dispatch($message);
    }
}
