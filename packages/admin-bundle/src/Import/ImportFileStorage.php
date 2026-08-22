<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import;

use Nubit\AdminBundle\Import\Exception\ImportException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Keeps uploaded import files on local disk for the life of the session.
 *
 * Local rather than the Flysystem abstraction the rest of the bundle uses, and
 * deliberately: the readers stream from a path, and an XLSX reader that has to
 * pull a hundred megabytes into memory to satisfy a filesystem interface
 * defeats the streaming it was written for.
 *
 * The file is kept after the dry run because applying re-reads it. It is the
 * evidence of what was imported, so retention is the application's decision,
 * not something this class quietly handles.
 */
final readonly class ImportFileStorage
{
    public function __construct(
        private string $directory,
        private Filesystem $filesystem = new Filesystem(),
    ) {}

    public function store(UploadedFile $file): string
    {
        $this->ensureDirectory();

        $name = sprintf(
            '%s-%s.%s',
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His'),
            bin2hex(random_bytes(8)),
            // The client's extension, never its filename: a name from an upload
            // is attacker-controlled and has no business becoming a path.
            preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension())) ?: 'csv',
        );

        $file->move($this->directory, $name);

        return $name;
    }

    public function absolutePath(string $storedName): string
    {
        // basename() strips any traversal a stored name could have picked up.
        $path = rtrim($this->directory, '/') . '/' . basename($storedName);

        if (!is_file($path)) {
            throw new ImportException('The uploaded file is no longer available; upload it again.');
        }

        return $path;
    }

    public function mediaType(string $path): string
    {
        if (!is_readable($path)) {
            return 'application/octet-stream';
        }

        $type = mime_content_type($path);

        return false === $type ? 'application/octet-stream' : $type;
    }

    public function delete(string $storedName): void
    {
        $this->filesystem->remove(rtrim($this->directory, '/') . '/' . basename($storedName));
    }

    private function ensureDirectory(): void
    {
        try {
            // Symfony's Filesystem tolerates the directory appearing between the
            // check and the call, which two concurrent uploads make routine.
            $this->filesystem->mkdir($this->directory, 0o775);
        } catch (\Throwable $exception) {
            throw new ImportException(
                sprintf('Could not create the import directory "%s".', $this->directory),
                previous: $exception,
            );
        }
    }
}
