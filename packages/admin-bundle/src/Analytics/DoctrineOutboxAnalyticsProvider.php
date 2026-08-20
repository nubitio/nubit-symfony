<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Analytics;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Analytics\Entity\AnalyticsOutboxEntry;
use Nubit\Platform\Analytics\Contract\AnalyticsProviderInterface;
use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;

/** Persists without flushing so the event joins the caller's business transaction. */
final readonly class DoctrineOutboxAnalyticsProvider implements AnalyticsProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function capture(SanitizedAnalyticsEvent $event): void
    {
        $this->entityManager->persist(new AnalyticsOutboxEntry($event));
    }
}
