<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export;

use Nubit\AdminBundle\Export\Entity\ExportJob;
use Nubit\Platform\Exception\NotFoundException;
use Nubit\Platform\Exception\ServiceException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Where queued exports are written.
 *
 * Local paths and a file handle, not the Flysystem abstraction the rest of the
 * bundle uses. The whole point of the queued path is that rows go to disk as
 * they are read; buffering a file in memory to hand it to a filesystem
 * interface would reintroduce the limit this exists to remove.
 */
final readonly class ExportFileStorage
{
    public function __construct(
        private string $directory,
        private Filesystem $filesystem = new Filesystem(),
    ) {}

    public function pathFor(ExportJob $job): string
    {
        $this->ensureDirectory();

        return sprintf('%s/%s.csv', rtrim($this->directory, '/'), (string) $job->getId());
    }

    /** @return resource */
    public function open(string $path)
    {
        $handle = fopen($path, 'w');

        if (false === $handle) {
            throw new ServiceException(sprintf('Could not open "%s" for writing.', $path));
        }

        return $handle;
    }

    public function size(string $path): int
    {
        $size = is_file($path) ? filesize($path) : false;

        return false === $size ? 0 : $size;
    }

    /** @return resource A handle the caller streams to the client. */
    public function read(string $path)
    {
        if (!is_readable($path)) {
            throw new NotFoundException('The exported file is no longer available.');
        }

        $handle = fopen($path, 'r');

        if (false === $handle) {
            throw new NotFoundException('The exported file could not be opened.');
        }

        return $handle;
    }

    public function delete(string $path): void
    {
        $this->filesystem->remove($path);
    }

    private function ensureDirectory(): void
    {
        try {
            $this->filesystem->mkdir($this->directory, 0o775);
        } catch (\Throwable $exception) {
            throw new ServiceException(
                sprintf('Could not create the export directory "%s".', $this->directory),
                previous: $exception,
            );
        }
    }
}
