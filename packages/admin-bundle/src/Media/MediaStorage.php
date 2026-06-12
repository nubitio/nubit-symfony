<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media;

use League\Flysystem\FilesystemException;
use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\Platform\Filesystem\FileManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Storage facade for the media library. Delegates to the platform FileManager
 * (Flysystem underneath, tenant-prefix aware) scoped to the configured media
 * directory. The backing FilesystemOperator is the bundle-created local
 * adapter by default, or any service the app points `nubit_admin.media.storage.filesystem`
 * at (e.g. an S3 filesystem from oneup/flysystem-bundle).
 */
final readonly class MediaStorage
{
    public function __construct(
        private FileManager $fileManager,
        private string $directory,
    ) {
    }

    /**
     * Writes the upload and returns an unpersisted Media row describing it.
     *
     * @throws FilesystemException
     */
    public function store(UploadedFile $file): Media
    {
        $media = new Media();
        $media->setPath($this->fileManager->upload($file, $this->directory));
        $media->setOriginalName($file->getClientOriginalName());
        $media->setMimeType($file->getClientMimeType());
        $media->setSize($file->getSize() !== false ? $file->getSize() : null);

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
