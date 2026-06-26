<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media;

use League\Flysystem\FilesystemException;
use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\Platform\Filesystem\FileManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Storage facade for the media library. Delegates to the platform FileManager
 * (Flysystem underneath, tenant-prefix aware) scoped to the configured media
 * directory. The backing FilesystemOperator is the bundle-created local
 * adapter by default, or any service the app points `nubit_admin.media.storage.filesystem`
 * at (e.g. an S3 filesystem from oneup/flysystem-bundle).
 */
final readonly class MediaStorage
{
    /**
     * @param list<string> $allowedMimes Allowlist of MIME types (server-detected). Empty = allow all.
     * @param int          $maxSize      Maximum file size in bytes. 0 = no limit.
     */
    public function __construct(
        private FileManager $fileManager,
        private string $directory,
        private array $allowedMimes = [],
        private int $maxSize = 0,
    ) {
    }

    /**
     * Validates, writes the upload, and returns an unpersisted Media row describing it.
     *
     * @throws BadRequestHttpException         if size exceeds the configured limit
     * @throws UnsupportedMediaTypeHttpException if the MIME type is not in the allowlist
     * @throws FilesystemException
     */
    public function store(UploadedFile $file): Media
    {
        $size = $file->getSize();

        if ($this->maxSize > 0 && $size !== false && $size > $this->maxSize) {
            throw new BadRequestHttpException(sprintf(
                'File size %d bytes exceeds the maximum of %d bytes.',
                $size,
                $this->maxSize,
            ));
        }

        // Use server-detected MIME (finfo), not the client-supplied Content-Type.
        $mime = $file->getMimeType() ?? 'application/octet-stream';

        if ($this->allowedMimes !== [] && !\in_array($mime, $this->allowedMimes, true)) {
            throw new UnsupportedMediaTypeHttpException(sprintf(
                'File type "%s" is not allowed. Allowed types: %s.',
                $mime,
                implode(', ', $this->allowedMimes),
            ));
        }

        $media = new Media();
        $media->setPath($this->fileManager->upload($file, $this->directory));
        $media->setOriginalName($file->getClientOriginalName());
        $media->setMimeType($mime);
        $media->setSize($size !== false ? $size : null);

        return $media;
    }

    /** @throws FilesystemException */
    public function read(Media $media): string
    {
        return $this->fileManager->read($this->locate($media));
    }

    /** @throws FilesystemException */
    public function delete(Media $media): void
    {
        if ($this->fileManager->exists($this->locate($media))) {
            $this->fileManager->delete($this->locate($media));
        }
    }

    /** Path relative to the storage root (before any tenant prefix). */
    public function locate(Media $media): string
    {
        return $this->directory . '/' . $media->getPath();
    }
}
