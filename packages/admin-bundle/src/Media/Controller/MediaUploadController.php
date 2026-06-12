<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media\Controller;

use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\AdminBundle\Media\MediaStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST /api/media — traditional multipart upload (field name `file`).
 * Returns the persisted Media resource; the client references it by IRI.
 */
final class MediaUploadController
{
    public function __construct(
        private readonly MediaStorage $storage,
    ) {
    }

    public function __invoke(Request $request): Media
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('"file" is required');
        }

        return $this->storage->store($file);
    }
}
