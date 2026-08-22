<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Export\Entity\ExportJob;
use Nubit\AdminBundle\Export\ExportFileStorage;
use Nubit\AdminBundle\Export\ExportRequestService;
use Nubit\AdminBundle\Export\Writer\QueuedExportWriterInterface;
use Nubit\AdminBundle\Resource\ResourceSegmentIndex;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Queued exports.
 *
 *   POST /api/exports/{resource}   ask for one
 *   GET  /api/exports              what you have asked for
 *   GET  /api/exports/{id}         status
 *   GET  /api/exports/{id}/file    the bytes, streamed
 *
 * A job belongs to whoever asked for it, and nobody else can read it. The file
 * is the result of that person's filters and row scope; handing it to another
 * user by identifier would be a way around both.
 */
final readonly class ExportJobController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExportRequestService $exports,
        private ExportFileStorage $storage,
        private ResourceSegmentIndex $segments,
        private QueuedExportWriterInterface $writer,
        private Security $security,
    ) {}

    public function request(Request $request, string $resource): JsonResponse
    {
        $resourceClass = $this->segments->resolve($resource);
        $requestedBy = $this->currentUserIdentifier();

        // The grid's own query parameters, carried through unchanged so the file
        // contains what the user was looking at.
        $filters = $request->query->all();

        $job = $this->exports->queue(
            $resourceClass,
            $filters,
            sprintf('%s-%s', $resource, date('Ymd-His')),
            $requestedBy,
        );

        return new JsonResponse(self::describe($job), Response::HTTP_ACCEPTED);
    }

    public function list(): JsonResponse
    {
        return new JsonResponse([
            'exports' => array_map(self::describe(...), $this->exports->recentFor($this->currentUserIdentifier())),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return new JsonResponse(self::describe($this->ownedJob($id)));
    }

    public function download(string $id): Response
    {
        $job = $this->ownedJob($id);

        if (ExportJob::STATUS_FAILED === $job->getStatus()) {
            throw new NotFoundHttpException(sprintf(
                'This export was not produced: %s',
                $job->getFailureReason() ?? 'unknown reason',
            ));
        }

        if (!$job->isReady()) {
            return new Response('', Response::HTTP_ACCEPTED, ['Retry-After' => '5']);
        }

        $path = (string) $job->getStoragePath();
        $handle = $this->storage->read($path);

        // Streamed, for the same reason it was written a row at a time: reading
        // the whole file into a response would put the limit back.
        $response = new StreamedResponse(static function () use ($handle): void {
            while (!feof($handle)) {
                echo (string) fread($handle, 8192);
                flush();
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="%s.csv"',
            addslashes($job->getFilename()),
        ));

        return $response;
    }

    /** @return array<string, mixed> */
    private static function describe(ExportJob $job): array
    {
        return [
            'id' => $job->getId(),
            'status' => $job->getStatus(),
            'filename' => $job->getFilename(),
            'rowCount' => $job->getRowCount(),
            'byteSize' => $job->getByteSize(),
            'failureReason' => $job->getFailureReason(),
            'createdAt' => $job->getCreatedAt()->format(\DATE_ATOM),
            'completedAt' => $job->getCompletedAt()?->format(\DATE_ATOM),
            'downloadUrl' => sprintf('/api/exports/%s/file', (string) $job->getId()),
        ];
    }

    private function ownedJob(string $id): ExportJob
    {
        $job = $this->entityManager->find(ExportJob::class, $id);

        // One answer for "does not exist" and "is not yours": telling them apart
        // turns the endpoint into a way to learn what other people export.
        if (!$job instanceof ExportJob || $job->getRequestedBy() !== $this->currentUserIdentifier()) {
            throw new NotFoundHttpException('Export not found.');
        }

        return $job;
    }

    private function currentUserIdentifier(): string
    {
        $user = $this->security->getUser();

        return null === $user ? throw new AccessDeniedHttpException() : $user->getUserIdentifier();
    }
}
