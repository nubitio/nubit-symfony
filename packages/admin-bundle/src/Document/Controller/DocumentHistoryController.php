<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document\Controller;

use Nubit\AdminBundle\Document\DocumentIssuer;
use Nubit\AdminBundle\Document\DocumentPayload;
use Nubit\AdminBundle\Document\ResourceLocator;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /api/documents/{resource}/{id} — every copy ever issued for one record,
 * newest first.
 *
 * The superseded copies are the point. "Which version did the customer
 * receive?" is a question the archive has to be able to answer, and a listing
 * that showed only the current copy could not.
 */
final readonly class DocumentHistoryController
{
    public function __construct(
        private DocumentIssuer $issuer,
        private ResourceLocator $locator,
    ) {}

    public function __invoke(string $resource, string $id): JsonResponse
    {
        $subject = $this->locator->locate($resource, $id);

        return new JsonResponse(['documents' => DocumentPayload::all($this->issuer->history($subject))]);
    }
}
