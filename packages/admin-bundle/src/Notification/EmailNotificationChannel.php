<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Notification;

use Nubit\Platform\Notification\Contract\NotificationChannelInterface;
use Nubit\Platform\Notification\NotificationMessage;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class EmailNotificationChannel implements NotificationChannelInterface
{
    public const string IDENTIFIER = 'email';

    public function __construct(
        private MailerInterface $mailer,
        private string $fromAddress,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function send(NotificationMessage $message): void
    {
        $email = (new Email())
            ->from($this->fromAddress)
            ->to($message->recipient)
            ->subject($message->subject)
            ->text($message->body);

        if (
            isset($message->context['html'])
            && \is_string($message->context['html'])
            && $message->context['html'] !== ''
        ) {
            $email->html($message->context['html']);
        }

        $this->mailer->send($email);
    }
}
