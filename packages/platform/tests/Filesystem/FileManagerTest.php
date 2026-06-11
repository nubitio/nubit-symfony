<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Filesystem;

use Nubit\Platform\Filesystem\FileManager;
use Nubit\Platform\Tenant\Context\TenantContext;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCheckExistence;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class FileManagerTest extends TestCase
{
    public function testWriteReadExistsAndDeleteUseTenantPrefixedPath(): void
    {
        $filesystem = new RecordingFilesystemOperator(['acme/documents/invoice.txt' => 'contents']);
        $context = new TenantContext();
        $context->setTenant(1, 'acme', null, null);
        $manager = new FileManager($filesystem, $context, new AsciiSlugger());

        $manager->write('documents/invoice.txt', 'contents');

        self::assertSame('contents', $manager->read('documents/invoice.txt'));
        self::assertTrue($manager->exists('documents/invoice.txt'));

        $manager->delete('documents/invoice.txt');

        self::assertSame([
            ['write', 'acme/documents/invoice.txt', 'contents'],
            ['read', 'acme/documents/invoice.txt'],
            ['fileExists', 'acme/documents/invoice.txt'],
            ['delete', 'acme/documents/invoice.txt'],
        ], $filesystem->calls);
    }

    public function testMissingTenantPassesPathsThroughUnchanged(): void
    {
        $filesystem = new RecordingFilesystemOperator(['documents/invoice.txt' => 'contents']);
        $manager = new FileManager($filesystem, new TenantContext(), new AsciiSlugger());

        $manager->read('documents/invoice.txt');
        $manager->exists('documents/invoice.txt');

        self::assertSame([
            ['read', 'documents/invoice.txt'],
            ['fileExists', 'documents/invoice.txt'],
        ], $filesystem->calls);
    }
}

/** @internal */
final class RecordingFilesystemOperator implements FilesystemOperator
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    /** @param array<string, string> $files */
    public function __construct(private array $files = [])
    {
    }

    /** @throws FilesystemException */
    public function fileExists(string $location): bool
    {
        $this->calls[] = ['fileExists', $location];

        return array_key_exists($location, $this->files);
    }

    public function directoryExists(string $location): bool
    {
        $this->calls[] = ['directoryExists', $location];

        return false;
    }

    /** @throws FilesystemException */
    public function has(string $location): bool
    {
        $this->calls[] = ['has', $location];

        return array_key_exists($location, $this->files);
    }

    public function read(string $location): string
    {
        $this->calls[] = ['read', $location];

        return $this->files[$location] ?? '';
    }

    /** @return resource */
    public function readStream(string $location)
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw UnableToCheckExistence::forLocation($location);
        }

        fwrite($stream, $this->read($location));
        rewind($stream);

        return $stream;
    }

    /** @throws FilesystemException */
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        $this->calls[] = ['listContents', $location, $deep];

        return new DirectoryListing([]);
    }

    public function lastModified(string $path): int
    {
        $this->calls[] = ['lastModified', $path];

        return 0;
    }

    /** @throws FilesystemException */
    public function fileSize(string $path): int
    {
        $this->calls[] = ['fileSize', $path];

        return strlen($this->files[$path] ?? '');
    }

    public function mimeType(string $path): string
    {
        $this->calls[] = ['mimeType', $path];

        return 'text/plain';
    }

    public function visibility(string $path): string
    {
        $this->calls[] = ['visibility', $path];

        return 'public';
    }

    /** @param array<string, mixed> $config */
    public function write(string $location, string $contents, array $config = []): void
    {
        unset($config);
        $this->calls[] = ['write', $location, $contents];
        $this->files[$location] = $contents;
    }

    /**
     * @param resource             $contents
     * @param array<string, mixed> $config
     */
    public function writeStream(string $location, $contents, array $config = []): void
    {
        unset($config);
        $this->calls[] = ['writeStream', $location];
        $this->files[$location] = stream_get_contents($contents) ?: '';
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->calls[] = ['setVisibility', $path, $visibility];
    }

    public function delete(string $location): void
    {
        $this->calls[] = ['delete', $location];
        unset($this->files[$location]);
    }

    public function deleteDirectory(string $location): void
    {
        $this->calls[] = ['deleteDirectory', $location];
    }

    /** @param array<string, mixed> $config */
    public function createDirectory(string $location, array $config = []): void
    {
        unset($config);
        $this->calls[] = ['createDirectory', $location];
    }

    /** @param array<string, mixed> $config */
    public function move(string $source, string $destination, array $config = []): void
    {
        unset($config);
        $this->calls[] = ['move', $source, $destination];
    }

    /** @param array<string, mixed> $config */
    public function copy(string $source, string $destination, array $config = []): void
    {
        unset($config);
        $this->calls[] = ['copy', $source, $destination];
    }
}
