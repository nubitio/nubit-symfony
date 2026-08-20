<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Analytics;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Analytics\Entity\AnalyticsOutboxEntry;
use Nubit\AdminBundle\Analytics\Message\DeliverAnalyticsOutbox;
use Nubit\Platform\Analytics\Contract\AnalyticsDeliveryProviderInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final readonly class DeliverAnalyticsOutboxHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AnalyticsDeliveryProviderInterface $deliveryProvider,
        private int $maximumDelaySeconds = 3600,
    ) {}

    public function __invoke(DeliverAnalyticsOutbox $message): void
    {
        $failure = null;
        $this->entityManager->wrapInTransaction(function () use ($message, &$failure): void {
            $entry = $this->entityManager->find(
                AnalyticsOutboxEntry::class,
                $message->entryId,
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$entry instanceof AnalyticsOutboxEntry) {
                return;
            }

            $now = new DateTimeImmutable();
            if (!$entry->isDue($now)) {
                return;
            }

            try {
                $this->deliveryProvider->deliver($entry->toEvent());
                $entry->markDelivered($now);
            } catch (Throwable $exception) {
                $entry->markFailed($exception, $now, $this->maximumDelaySeconds);
                $failure = $exception;
            }

            $this->entityManager->flush();
        });

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }
}
