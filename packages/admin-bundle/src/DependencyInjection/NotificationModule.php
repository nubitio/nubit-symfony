<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use Nubit\AdminBundle\Notification\EmailNotificationChannel;
use Nubit\AdminBundle\Notification\EventListener\CurrentRecipientFilterListener;
use Nubit\AdminBundle\Notification\InAppNotificationChannel;
use Nubit\AdminBundle\Notification\MessengerNotificationDispatcher;
use Nubit\AdminBundle\Notification\SendNotificationHandler;
use Nubit\Platform\Notification\Contract\NotificationDispatcherInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\Mailer\MailerInterface;

final class NotificationModule
{
    private function __construct() {}

    /**
     * @param array{enabled: bool, from_address: string, in_app: array{enabled: bool}} $config
     */
    public static function load(array $config, DefaultsConfigurator $services): void
    {
        // symfony/mailer is a `suggest`, not a hard requirement: an app that
        // turns notifications on for in-app delivery only must not be forced
        // to install a mailer just to boot the container.
        if (interface_exists(MailerInterface::class)) {
            $services->set(EmailNotificationChannel::class)->arg('$fromAddress', $config['from_address']);
        }

        if ($config['in_app']['enabled']) {
            $services->set(InAppNotificationChannel::class);
            $services->set(CurrentRecipientFilterListener::class);
        }

        $services->set(SendNotificationHandler::class);

        $services->set(MessengerNotificationDispatcher::class);
        $services->alias(NotificationDispatcherInterface::class, MessengerNotificationDispatcher::class);
    }
}
