<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Notification\Entity\Notification;
use Nubit\Platform\Notification\Contract\NotificationChannelInterface;
use Nubit\Platform\Notification\NotificationMessage;

/**
 * Persists the notification. API Platform's own Mercure integration
 * (`mercure: true` on the Notification resource) publishes the update from
 * there — no manual Hub::publish() call needed here, same as any other
 * `mercure: true` resource in the app.
 */
final readonly class InAppNotificationChannel implements NotificationChannelInterface
{
    public const string IDENTIFIER = 'in_app';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function send(NotificationMessage $message): void
    {
        $notification = new Notification($message->recipient, $message->subject, $message->body);
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }
}
