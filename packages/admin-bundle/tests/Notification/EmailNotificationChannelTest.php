<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Notification;

use Nubit\AdminBundle\Notification\EmailNotificationChannel;
use Nubit\Platform\Notification\NotificationMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class EmailNotificationChannelTest extends TestCase
{
    public function testIdentifierIsEmail(): void
    {
        $channel = new EmailNotificationChannel($this->createStub(MailerInterface::class), 'no-reply@nubit.io');

        static::assertSame('email', $channel->getIdentifier());
    }

    public function testSendsAPlainTextEmailWithTheConfiguredFromAddress(): void
    {
        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(static::once())
            ->method('send')
            ->with(static::callback(function (RawMessage $email) use (&$sent) {
                $sent = $email;

                return true;
            }));

        $channel = new EmailNotificationChannel($mailer, 'no-reply@nubit.io');
        $channel->send(new NotificationMessage(
            recipient: 'ops@acme.test',
            subject: 'Invoice INV-0001 confirmed',
            body: 'The invoice was confirmed.',
        ));

        static::assertInstanceOf(Email::class, $sent);
        static::assertSame('no-reply@nubit.io', $sent->getFrom()[0]->getAddress());
        static::assertSame('ops@acme.test', $sent->getTo()[0]->getAddress());
        static::assertSame('Invoice INV-0001 confirmed', $sent->getSubject());
        static::assertSame('The invoice was confirmed.', $sent->getTextBody());
        static::assertNull($sent->getHtmlBody());
    }

    public function testAddsAnHtmlBodyWhenContextProvidesOne(): void
    {
        $sent = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $email) use (&$sent): void {
            $sent = $email;
        });

        $channel = new EmailNotificationChannel($mailer, 'no-reply@nubit.io');
        $channel->send(new NotificationMessage(
            recipient: 'ops@acme.test',
            subject: 'Subject',
            body: 'Plain body',
            context: ['html' => '<p>Plain body</p>'],
        ));

        static::assertInstanceOf(Email::class, $sent);
        static::assertSame('<p>Plain body</p>', $sent->getHtmlBody());
    }
}
