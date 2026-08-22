<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Export\Entity\ExportJob;
use Nubit\AdminBundle\Export\Message\RunExport;
use Nubit\ApiPlatform\Doctrine\GridScaleRegistry;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Decides whether an export is worth queueing, and queues it.
 *
 * The threshold is per resource, because "too big" is a property of the row and
 * not of the row count: a movements table with eight columns and a documents
 * table with forty do not fall over at the same place.
 *
 * The estimate is deliberately cheap. Counting exactly in order to decide
 * whether counting is expensive would be its own joke.
 */
final readonly class ExportRequestService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GridScaleRegistry $gridScales,
        private MessageBusInterface $messageBus,
        private int $defaultInlineLimit = 5_000,
    ) {}

    /** @param class-string $resourceClass */
    public function inlineLimitFor(string $resourceClass): int
    {
        return $this->gridScales->find($resourceClass)?->inlineExportLimit ?? $this->defaultInlineLimit;
    }

    /**
     * @param class-string         $resourceClass
     * @param array<string, mixed> $filters the grid query as the user had it
     */
    public function queue(string $resourceClass, array $filters, string $filename, ?string $requestedBy): ExportJob
    {
        $job = new ExportJob($resourceClass, $filters, $filename, $requestedBy);

        $this->entityManager->persist($job);
        // Flushed before dispatching so the worker cannot pick the message up
        // before the row it names exists.
        $this->entityManager->flush();

        $this->messageBus->dispatch(new RunExport((string) $job->getId()));

        return $job;
    }

    /** @return list<ExportJob> Newest first, for the person who asked. */
    public function recentFor(string $requestedBy): array
    {
        /** @var list<ExportJob> $jobs */
        $jobs = $this->entityManager->getRepository(ExportJob::class)->findBy(
            ['requestedBy' => $requestedBy],
            ['createdAt' => 'DESC'],
            25,
        );

        return $jobs;
    }
}
