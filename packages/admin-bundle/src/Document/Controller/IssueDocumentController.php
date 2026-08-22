<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Document\DocumentIssuer;
use Nubit\AdminBundle\Document\DocumentPayload;
use Nubit\AdminBundle\Document\ResourceLocator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/documents/{resource}/{id} — issues the document for one record.
 *
 * Issuing is idempotent: calling it twice returns the same document, because a
 * document is a record rather than a rendering. `?reissue=1` asks for a
 * correction instead, which emits a new copy referencing the previous one.
 */
final readonly class IssueDocumentController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentIssuer $issuer,
        private ResourceLocator $locator,
        private Security $security,
    ) {}

    public function __invoke(Request $request, string $resource, string $id): JsonResponse
    {
        $subject = $this->locator->locate($resource, $id);
        $issuedBy = $this->security->getUser()?->getUserIdentifier();

        $reissue = $request->query->getBoolean('reissue');
        $document = $reissue ? $this->issuer->reissue($subject, $issuedBy) : $this->issuer->issue($subject, $issuedBy);

        $this->entityManager->flush();

        return new JsonResponse(DocumentPayload::of($document), $reissue ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
