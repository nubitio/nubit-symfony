<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Authorization\PermissionCatalog;
use Nubit\AdminBundle\Authorization\PermissionResolver;
use Nubit\AdminBundle\Document\DocumentIssuer;
use Nubit\AdminBundle\Document\Entity\IssuedDocument;
use Nubit\AdminBundle\Security\PrivilegedAccess;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/documents/{id}/file — hands back the exact bytes that were issued.
 *
 * Never re-renders. A document still in the queue answers 202 rather than
 * producing a second, possibly different, copy just because someone clicked
 * before the worker got to it.
 */
final readonly class DownloadDocumentController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentIssuer $issuer,
        private PrivilegedAccess $access,
        private ?PermissionResolver $permissions = null,
    ) {}

    public function __invoke(string $id): Response
    {
        $document = $this->entityManager->find(IssuedDocument::class, $id);
        if (!$document instanceof IssuedDocument) {
            throw new NotFoundHttpException('Document not found.');
        }

        $this->assertCanRead($document);

        if (IssuedDocument::STATUS_PENDING === $document->getStatus()) {
            return new Response('', Response::HTTP_ACCEPTED, [
                'Retry-After' => '2',
            ]);
        }

        if (!$document->isReady()) {
            throw new NotFoundHttpException(sprintf(
                'Document %s was not produced: %s',
                $id,
                $document->getFailureReason() ?? 'unknown reason',
            ));
        }

        $filename = preg_replace('/[\r\n"\\\\]/', '', $document->getDocumentNumber() ?? (string) $document->getId())
        ?: 'document';

        return new Response($this->issuer->bytes($document), Response::HTTP_OK, [
            'Content-Type' => $document->getMediaType(),
            'Content-Disposition' => sprintf('inline; filename="%s.pdf"', $filename),
            // The bytes behind an issued document never change — that is the
            // whole point of the module — so they cache indefinitely.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Document-Checksum' => (string) $document->getChecksum(),
        ]);
    }

    private function assertCanRead(IssuedDocument $document): void
    {
        if (null === $this->permissions || $this->access->isAdmin()) {
            return;
        }

        $permission = PermissionCatalog::prefixFor($document->getResourceClass()) . '.read';
        if (!$this->permissions->hasPermission($this->access->user(), $permission)) {
            throw new AccessDeniedHttpException();
        }
    }
}
