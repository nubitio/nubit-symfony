<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Import\Entity\ImportSession;
use Nubit\AdminBundle\Import\Exception\ImportException;
use Nubit\AdminBundle\Import\ImportService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The import endpoints.
 *
 *   POST /api/imports/{resource}      upload + dry run
 *   GET  /api/imports/{id}            the report
 *   PATCH /api/imports/{id}           correct the mapping, re-run the dry run
 *   POST /api/imports/{id}/confirm    apply
 *
 * Uploading never writes business data. That separation is the feature.
 */
final readonly class ImportController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportService $imports,
        private Security $security,
    ) {}

    public function start(Request $request, string $resource): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ImportException('Upload the spreadsheet as a multipart field named "file".');
        }

        /** @var array<string, int|string> $mapping */
        $mapping = self::arrayParam($request, 'mapping');

        $session = $this->imports->start(
            $resource,
            $file,
            $this->security->getUser()?->getUserIdentifier(),
            $mapping,
            (string) $request->request->get('numberFormat', 'auto'),
        );

        return new JsonResponse(self::payload($session), Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        return new JsonResponse(self::payload($this->session($id)));
    }

    public function remap(Request $request, string $id): JsonResponse
    {
        /** @var array<string, int|string> $mapping */
        $mapping = self::arrayParam($request, 'mapping');

        $numberFormat = $request->request->get('numberFormat') ?? self::jsonBody($request)['numberFormat'] ?? null;

        $session = $this->imports->remap(
            $this->session($id),
            $mapping,
            is_string($numberFormat) ? $numberFormat : null,
        );

        return new JsonResponse(self::payload($session));
    }

    public function confirm(string $id): JsonResponse
    {
        return new JsonResponse(self::payload($this->imports->confirm($this->session($id))));
    }

    private function session(string $id): ImportSession
    {
        $session = $this->entityManager->find(ImportSession::class, $id);

        if (!$session instanceof ImportSession) {
            throw new NotFoundHttpException('Import session not found.');
        }

        return $session;
    }

    /** @return array<string, mixed> */
    private static function payload(ImportSession $session): array
    {
        return [
            'id' => $session->getId(),
            'resource' => $session->getResourceClass(),
            'filename' => $session->getFilename(),
            'status' => $session->getStatus(),
            'numberFormat' => $session->getNumberFormat(),
            'mapping' => $session->getMapping(),
            'report' => $session->getReport(),
            'createdAt' => $session->getCreatedAt()->format(\DATE_ATOM),
            'appliedAt' => $session->getAppliedAt()?->format(\DATE_ATOM),
            'createdBy' => $session->getCreatedBy(),
        ];
    }

    /**
     * Reads a parameter that may arrive as a multipart field or in a JSON body.
     *
     * The upload is multipart by necessity and the correction is JSON by
     * convention, so both shapes reach these endpoints from the same client.
     *
     * @return array<string, mixed>
     */
    private static function arrayParam(Request $request, string $name): array
    {
        /** @var mixed $value */
        $value = $request->request->all()[$name] ?? self::jsonBody($request)[$name] ?? [];

        if (!is_array($value)) {
            return [];
        }

        $result = [];
        /** @var mixed $entry */
        foreach ($value as $key => $entry) {
            $result[(string) $key] = $entry;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function jsonBody(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        /** @var mixed $entry */
        foreach ($decoded as $key => $entry) {
            $result[(string) $key] = $entry;
        }

        return $result;
    }
}
