<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export\Message;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Export\Entity\ExportJob;
use Nubit\AdminBundle\Export\QueuedExportRunner;
use Nubit\Platform\Notification\Contract\NotificationDispatcherInterface;
use Nubit\Platform\Notification\NotificationMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a queued export and tells the person who asked.
 *
 * The notification is the point of queueing at all: an export nobody is told
 * about is an export somebody sits and refreshes a page waiting for. It is
 * optional, because the notification module is — an application without it
 * still gets the file, and still gets a status endpoint to poll.
 */
#[AsMessageHandler]
final readonly class RunExportHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private QueuedExportRunner $runner,
        private ?NotificationDispatcherInterface $notifications = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(RunExport $message): void
    {
        $job = $this->entityManager->find(ExportJob::class, $message->jobId);

        if (!$job instanceof ExportJob) {
            // The row is gone: nothing to run and nothing to retry. Failing here
            // would park a message that can never succeed.
            $this->logger->warning('Skipping an export for a job that no longer exists.', [
                'job' => $message->jobId,
            ]);

            return;
        }

        $this->runner->run($job);

        $recipient = $job->getRequestedBy();
        if (null === $this->notifications || null === $recipient) {
            return;
        }

        $this->notifications->dispatch(new NotificationMessage(
            recipient: $recipient,
            subject: $job->isReady() ? 'Your export is ready' : 'Your export could not be produced',
            body: $job->isReady()
                ? sprintf('%s — %d rows.', $job->getFilename(), (int) $job->getRowCount())
                : (string) $job->getFailureReason(),
            context: [
                'exportId' => $job->getId(),
                'downloadUrl' => sprintf('/api/exports/%s/file', (string) $job->getId()),
            ],
        ));
    }
}
