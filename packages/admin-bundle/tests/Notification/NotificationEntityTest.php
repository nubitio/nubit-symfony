<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Notification;

use Nubit\AdminBundle\Notification\Entity\Notification;
use PHPUnit\Framework\TestCase;

final class NotificationEntityTest extends TestCase
{
    public function testStartsUnread(): void
    {
        $notification = new Notification('user-42', 'Subject', 'Body');

        static::assertFalse($notification->isRead());
        static::assertNull($notification->getReadAt());
    }

    public function testSetReadTrueStampsReadAt(): void
    {
        $notification = new Notification('user-42', 'Subject', 'Body');

        $notification->setRead(true);

        static::assertTrue($notification->isRead());
        static::assertNotNull($notification->getReadAt());
    }

    public function testSetReadFalseClearsReadAt(): void
    {
        $notification = new Notification('user-42', 'Subject', 'Body');
        $notification->setRead(true);

        $notification->setRead(false);

        static::assertFalse($notification->isRead());
        static::assertNull($notification->getReadAt());
    }
}
