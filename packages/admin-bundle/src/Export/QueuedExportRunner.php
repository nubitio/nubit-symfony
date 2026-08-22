<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Authorization\RowScopeApplier;
use Nubit\AdminBundle\Export\Entity\ExportJob;
use Nubit\AdminBundle\Export\Writer\QueuedExportWriterInterface;
use Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Writes a queued export to disk, one row at a time.
 *
 * Two things make this different from the inline export, and both are about the
 * export somebody actually needed rather than the one they demoed with:
 *
 *  - rows are streamed. Doctrine's `toIterable()` yields entities as they
 *    arrive, and the unit of work is cleared periodically — without that, the
 *    identity map holds every row and a large export is an out-of-memory error
 *    at the ninety percent mark;
 *  - the file is written through a streaming writer. PhpSpreadsheet builds the
 *    whole workbook in memory before emitting a byte, which is the same failure
 *    by another route; OpenSpout appends each row to the sheet as it arrives.
 *
 * The user who asked is resolved and their row scope re-applied. A worker has
 * no session, and an export that quietly dropped scope would hand a warehouse
 * supervisor the whole company asynchronously, with nobody watching.
 */
final readonly class QueuedExportRunner
{
    /** @param UserProviderInterface<\Symfony\Component\Security\Core\User\UserInterface> $userProvider */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExportFileStorage $storage,
        private DataGridFilter $gridFilter,
        private RowScopeApplier $rowScope,
        private ExportRowMapper $rowMapper,
        private QueuedExportWriterInterface $writer,
        private ?UserProviderInterface $userProvider = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function run(ExportJob $job): ExportJob
    {
        if ($job->isReady()) {
            // A redelivered message must not rewrite a file somebody already
            // downloaded, nor spend the work again.
            return $job;
        }

        $job->markRunning();
        $this->entityManager->flush();

        try {
            [$path, $rows, $bytes] = $this->write($job);
            $job->markReady($path, $rows, $bytes);
        } catch (\Throwable $exception) {
            $this->logger->error('A queued export failed.', [
                'job' => $job->getId(),
                'resource' => $job->getResourceClass(),
                'exception' => $exception->getMessage(),
            ]);
            $job->markFailed($exception->getMessage());
        }

        $this->entityManager->flush();

        return $job;
    }

    /** @return array{string, int, int} path, row count, byte size */
    private function write(ExportJob $job): array
    {
        $resourceClass = $job->getResourceClass();
        $metadata = $this->entityManager->getClassMetadata($resourceClass);

        $queryBuilder = $this->entityManager->createQueryBuilder()->select('o')->from($resourceClass, 'o');

        $this->applyFilters($queryBuilder, $job, $resourceClass);
        $this->rowScope->apply($queryBuilder, $resourceClass, $this->requestingUser($job));

        $columns = $this->rowMapper->columnsFor($resourceClass);
        $path = $this->storage->pathFor($job, $this->writer->extension());
        $properties = array_keys($columns);

        $this->writer->open($path, $columns);
        $rows = 0;

        try {
            /** @var object $entity */
            foreach ($queryBuilder->getQuery()->toIterable() as $entity) {
                $this->writer->writeRow($this->rowMapper->row($entity, $properties));
                ++$rows;

                // Detached as it goes: otherwise the identity map holds every
                // row and a large export dies at the ninety percent mark.
                $this->entityManager->detach($entity);
            }
        } finally {
            $this->writer->close();
        }

        unset($metadata);

        return [$path, $rows, $this->storage->size($path)];
    }

    /** @param class-string $resourceClass */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $queryBuilder, ExportJob $job, string $resourceClass): void
    {
        $filters = $job->getFilters();
        if ([] === $filters) {
            return;
        }

        // The same filter object the grid used, so the file contains exactly the
        // rows the user was looking at when they pressed the button. Rebuilding
        // the predicates here would be a second implementation to drift.
        foreach (['sort', 'filter', 'searchValue'] as $parameter) {
            if (!isset($filters[$parameter])) {
                continue;
            }

            $this->gridFilter->apply($queryBuilder, new QueryNameGenerator(), $resourceClass, null, [
                'filters' => $filters,
            ]);

            return;
        }
    }

    private function requestingUser(ExportJob $job): ?\Symfony\Component\Security\Core\User\UserInterface
    {
        $identifier = $job->getRequestedBy();

        if (null === $identifier || null === $this->userProvider) {
            return null;
        }

        try {
            return $this->userProvider->loadUserByIdentifier($identifier);
        } catch (\Throwable) {
            // The account was removed between the request and the run. Returning
            // null would drop row scope, so the export is failed instead.
            throw new \RuntimeException(sprintf(
                'The account that requested this export ("%s") no longer exists, so its row scope cannot be '
                . 'reapplied. Refusing to export without it.',
                $identifier,
            ));
        }
    }
}
