<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document;

use Nubit\Platform\Exception\NotFoundException;
use Nubit\Platform\Exception\ServiceException;
use Nubit\Platform\Filesystem\FileManager;

/**
 * Where issued documents live, and how they are addressed.
 *
 * Paths are date-partitioned and keyed by the document's own identifier. Two
 * consequences worth being deliberate about: nothing about the business record
 * leaks into a filename, and reissuing never collides with the copy it
 * supersedes — which it would if the path were derived from the invoice number.
 */
final readonly class DocumentStorage
{
    public function __construct(
        private FileManager $fileManager,
        private string $directory = 'documents',
    ) {}

    public function pathFor(string $documentId, \DateTimeImmutable $issuedAt, string $extension = 'pdf'): string
    {
        return sprintf('%s/%s/%s.%s', trim($this->directory, '/'), $issuedAt->format('Y/m'), $documentId, $extension);
    }

    public function write(string $path, string $contents): void
    {
        try {
            $this->fileManager->write($path, $contents);
        } catch (\Throwable $exception) {
            throw new ServiceException(
                sprintf('Could not store the issued document at "%s".', $path),
                previous: $exception,
            );
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->fileManager->read($path);
        } catch (\Throwable $exception) {
            // The row says the document was issued but the bytes are gone. That
            // is a broken archive, not a missing page, and it should read that
            // way in the log.
            throw new NotFoundException(
                sprintf('The stored bytes for document "%s" are missing.', $path),
                previous: $exception,
            );
        }
    }

    public function exists(string $path): bool
    {
        try {
            return $this->fileManager->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }
}
