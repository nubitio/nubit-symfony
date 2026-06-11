<?php

declare(strict_types=1);

namespace Nubit\Platform\Filesystem;

use Nubit\Platform\Tenant\Context\TenantContext;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

readonly class FileManager
{
    public function __construct(
        private FilesystemOperator $defaultFilesystem,
        private TenantContext $tenantContext,
        private SluggerInterface $slugger,
    ) {
    }

    /** @throws FilesystemException */
    public function write(string $location, string $content): void
    {
        $this->defaultFilesystem->write($this->prefixPath($location), $content);
    }

    /** @throws FilesystemException */
    public function read(string $fileName): string
    {
        return $this->defaultFilesystem->read($this->prefixPath($fileName));
    }

    /** @throws FilesystemException */
    public function exists(string $fileName): bool
    {
        return $this->defaultFilesystem->fileExists($this->prefixPath($fileName));
    }

    /** @throws FilesystemException */
    public function delete(string $fileName): void
    {
        $this->defaultFilesystem->delete($this->prefixPath($fileName));
    }

    /**
     * @return DirectoryListing<StorageAttributes>
     *
     * @throws FilesystemException
     */
    public function listContents(string $directory = ''): DirectoryListing
    {
        return $this->defaultFilesystem->listContents($this->prefixPath($directory));
    }

    /** @throws FilesystemException */
    public function upload(UploadedFile $file, string $targetDir): string
    {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $safeName = $this->slugger->slug($originalName);
        $fileName = $safeName . '-' . uniqid() . '.' . $file->guessExtension();

        $stream = fopen($file->getPathname(), 'r');

        $this->defaultFilesystem->writeStream(
            $this->prefixPath($targetDir . '/' . $fileName),
            $stream,
        );

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $fileName;
    }

    private function prefixPath(string $path): string
    {
        $tenantName = $this->tenantContext->getTenantName();
        if ($tenantName === null) {
            return $path;
        }

        return $tenantName . '/' . $path;
    }
}
