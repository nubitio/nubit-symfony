<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Media\MediaStorage;
use Nubit\AdminBundle\Media\Serializer\MediaNormalizer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST /api/media — traditional multipart upload (field name `file`).
 *
 * A plain route rather than an ApiResource operation: it persists and
 * serializes the Media itself, so it behaves the same with or without
 * api_platform.use_symfony_listeners (custom operation controllers need the
 * listeners mode, which is off by default in API Platform 4).
 */
final class MediaUploadController
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaNormalizer $normalizer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('"file" is required');
        }

        $media = $this->storage->store($file);

        $this->entityManager->persist($media);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizer->normalize($media), JsonResponse::HTTP_CREATED);
    }
}
