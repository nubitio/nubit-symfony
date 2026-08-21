<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Notification\Entity\Notification;
use Nubit\AdminBundle\Notification\InAppNotificationChannel;
use Nubit\Platform\Notification\NotificationMessage;
use PHPUnit\Framework\TestCase;

final class InAppNotificationChannelTest extends TestCase
{
    public function testIdentifierIsInApp(): void
    {
        $channel = new InAppNotificationChannel($this->createStub(EntityManagerInterface::class));

        static::assertSame('in_app', $channel->getIdentifier());
    }

    public function testPersistsANotificationBuiltFromTheMessage(): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(static::once())
            ->method('persist')
            ->with(static::callback(function (Notification $notification) use (&$persisted) {
                $persisted = $notification;

                return true;
            }));
        $entityManager->expects(static::once())->method('flush');

        (new InAppNotificationChannel($entityManager))->send(new NotificationMessage(
            recipient: 'user-42',
            subject: 'Invoice confirmed',
            body: 'INV-0001 was confirmed.',
        ));

        static::assertInstanceOf(Notification::class, $persisted);
        static::assertSame('user-42', $persisted->getRecipient());
        static::assertSame('Invoice confirmed', $persisted->getSubject());
        static::assertSame('INV-0001 was confirmed.', $persisted->getBody());
        static::assertFalse($persisted->isRead());
    }
}
