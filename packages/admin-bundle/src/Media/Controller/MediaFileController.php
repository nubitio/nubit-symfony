<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media\Controller;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\AdminBundle\Media\MediaStorage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/media/{id}/file — streams the stored bytes through PHP. This is
 * the default URL the bundle's MediaUrlResolver emits: it works identically
 * for local storage and private S3 buckets, behind the same auth as the rest
 * of /api. Apps wanting direct CDN/S3 URLs implement MediaUrlResolverInterface
 * instead and this route simply goes unused.
 */
final class MediaFileController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaStorage $storage,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $media = $this->entityManager->find(Media::class, $id);
        if (!$media instanceof Media) {
            throw new NotFoundHttpException('Media not found.');
        }

        try {
            $contents = $this->storage->read($media);
        } catch (FilesystemException) {
            throw new NotFoundHttpException('Media file missing from storage.');
        }

        $fileName = $media->getOriginalName() ?? $media->getPath();

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $media->getMimeType() ?? 'application/octet-stream',
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes($fileName)),
            // Upload filenames are unique (slug + uniqid), so the bytes behind
            // a given media id never change — let browsers cache aggressively.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
