<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document\Message;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Document\DocumentIssuer;
use Nubit\AdminBundle\Document\Entity\IssuedDocument;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Renders a queued document.
 *
 * Idempotent on purpose: {@see DocumentIssuer::render()} returns immediately for
 * a document that is already ready, so a redelivered message cannot replace
 * bytes somebody has already been given.
 */
#[AsMessageHandler]
final readonly class RenderDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentIssuer $issuer,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(RenderDocument $message): void
    {
        $document = $this->entityManager->find(IssuedDocument::class, $message->documentId);

        if (!$document instanceof IssuedDocument) {
            // The row is gone: nothing to render and nothing to retry. Failing
            // here would park a message that can never succeed.
            $this->logger->warning('Skipping a render for a document that no longer exists.', [
                'document' => $message->documentId,
            ]);

            return;
        }

        $this->issuer->render($document);
    }
}
