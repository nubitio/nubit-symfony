<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Notification;

use Nubit\Platform\Notification\Contract\NotificationChannelInterface;
use Nubit\Platform\Notification\NotificationMessage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resolves NotificationMessage::$channels against every service tagged
 * `nubit.admin.notification_channel` and sends through each match — the
 * message itself is the Messenger envelope (it's already a plain,
 * Messenger-serializable DTO), so no separate wrapper message is needed.
 */
#[AsMessageHandler]
final readonly class SendNotificationHandler
{
    /**
     * @param iterable<NotificationChannelInterface> $channels
     */
    public function __construct(
        #[AutowireIterator('nubit.admin.notification_channel')]
        private iterable $channels,
    ) {
    }

    public function __invoke(NotificationMessage $message): void
    {
        $channels = $this->resolveChannels($message);

        if ($channels === []) {
            throw new \RuntimeException(sprintf(
                'No notification channel registered matches %s.',
                $message->channels === [] ? 'any registered channel (none are registered)' : implode(', ', $message->channels),
            ));
        }

        foreach ($channels as $channel) {
            $channel->send($message);
        }
    }

    /**
     * @return list<NotificationChannelInterface>
     */
    private function resolveChannels(NotificationMessage $message): array
    {
        $all = iterator_to_array($this->channels, preserve_keys: false);

        if ($message->channels === []) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (NotificationChannelInterface $channel): bool => \in_array(
                $channel->getIdentifier(),
                $message->channels,
                true,
            ),
        ));
    }
}
