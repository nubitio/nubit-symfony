<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Notification;

use Nubit\AdminBundle\Notification\SendNotificationHandler;
use Nubit\Platform\Notification\Contract\NotificationChannelInterface;
use Nubit\Platform\Notification\NotificationMessage;
use PHPUnit\Framework\TestCase;

final class FakeChannel implements NotificationChannelInterface
{
    /** @var list<NotificationMessage> */
    public array $sent = [];

    public function __construct(
        private readonly string $identifier,
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function send(NotificationMessage $message): void
    {
        $this->sent[] = $message;
    }
}

final class SendNotificationHandlerTest extends TestCase
{
    public function testSendsThroughEveryChannelWhenNoneAreRequested(): void
    {
        $email = new FakeChannel('email');
        $slack = new FakeChannel('slack');
        $handler = new SendNotificationHandler([$email, $slack]);

        $message = new NotificationMessage('ops@acme.test', 'Subject', 'Body');
        $handler($message);

        static::assertSame([$message], $email->sent);
        static::assertSame([$message], $slack->sent);
    }

    public function testSendsOnlyThroughChannelsListedOnTheMessage(): void
    {
        $email = new FakeChannel('email');
        $slack = new FakeChannel('slack');
        $handler = new SendNotificationHandler([$email, $slack]);

        $message = new NotificationMessage('ops@acme.test', 'Subject', 'Body', channels: ['slack']);
        $handler($message);

        static::assertSame([], $email->sent);
        static::assertSame([$message], $slack->sent);
    }

    public function testThrowsWhenNoRegisteredChannelMatches(): void
    {
        $handler = new SendNotificationHandler([new FakeChannel('email')]);

        $this->expectException(\RuntimeException::class);

        $handler(new NotificationMessage('ops@acme.test', 'Subject', 'Body', channels: ['sms']));
    }

    public function testThrowsWhenNoChannelsAreRegisteredAtAll(): void
    {
        $handler = new SendNotificationHandler([]);

        $this->expectException(\RuntimeException::class);

        $handler(new NotificationMessage('ops@acme.test', 'Subject', 'Body'));
    }
}
