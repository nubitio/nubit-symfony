<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection\Compiler;

use Nubit\AdminBundle\Notification\EmailNotificationChannel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Drops the email notification channel when the application has no mailer.
 *
 * NotificationModule can only check whether symfony/mailer is *installed*,
 * which is not the same question: the package is frequently present as a
 * transitive dependency while `framework.mailer` is never configured, and then
 * the channel's autowired MailerInterface argument has nothing to resolve to.
 * The application fails to compile its container with an autowiring error that
 * says nothing about notifications.
 *
 * Service availability is only knowable once every extension has run, which is
 * what a compiler pass is for.
 */
final class RemoveEmailChannelWithoutMailerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(EmailNotificationChannel::class)) {
            return;
        }

        if ($container->has(MailerInterface::class) || $container->has('mailer.mailer')) {
            return;
        }

        $container->removeDefinition(EmailNotificationChannel::class);
    }
}
