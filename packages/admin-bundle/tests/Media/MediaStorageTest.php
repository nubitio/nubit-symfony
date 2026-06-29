<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Media;

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\AdminBundle\Media\MediaStorage;
use Nubit\Platform\Filesystem\FileManager;
use Nubit\Platform\Tenant\Context\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MediaStorageTest extends TestCase
{
    private Filesystem $filesystem;
    private MediaStorage $storage;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->tenantContext = new TenantContext();
        $fileManager = new FileManager($this->filesystem, $this->tenantContext, new AsciiSlugger());
        $this->storage = new MediaStorage($fileManager, 'media');
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function testStoreWritesTheFileUnderTheMediaDirectory(): void
    {
        $media = $this->storage->store($this->makeUpload('Company Logo.png', 'png-bytes'));

        self::assertTrue($this->filesystem->fileExists('media/' . $media->getPath()));
    }

    public function testStoreSlugsTheFilenameAndKeepsTheExtension(): void
    {
        $media = $this->storage->store($this->makeUpload('Año Nuevo Ñandú.txt', 'hello'));

        self::assertMatchesRegularExpression('/^ano-nuevo-nandu-[0-9a-f.]+\.txt$/i', $media->getPath());
    }

    public function testStoreCapturesUploadMetadata(): void
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        self::assertNotFalse($contents);

        $media = $this->storage->store($this->makeUpload('photo.png', $contents));

        self::assertSame('photo.png', $media->getOriginalName());
        self::assertSame('image/png', $media->getMimeType());
        self::assertSame(\strlen($contents), $media->getSize());
    }

    public function testStoreRespectsTheTenantPrefix(): void
    {
        $this->tenantContext->setTenant(1, 'acme', null, null);

        $media = $this->storage->store($this->makeUpload('doc.txt', 'x'));

        self::assertTrue($this->filesystem->fileExists('acme/media/' . $media->getPath()));
    }

    // ── read / delete round-trip ──────────────────────────────────────────────

    public function testReadReturnsTheStoredBytes(): void
    {
        $media = $this->storage->store($this->makeUpload('doc.txt', 'round-trip-contents'));

        self::assertSame('round-trip-contents', $this->storage->read($media));
    }

    public function testDeleteRemovesTheFile(): void
    {
        $media = $this->storage->store($this->makeUpload('doc.txt', 'x'));

        $this->storage->delete($media);

        self::assertFalse($this->filesystem->fileExists('media/' . $media->getPath()));
    }

    public function testDeleteToleratesAMissingFile(): void
    {
        $media = (new Media())->setPath('never-stored.txt');

        $this->storage->delete($media);

        self::assertFalse($this->filesystem->fileExists('media/never-stored.txt'));
    }

    private function makeUpload(string $clientName, string $contents): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nubit_media_test_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $contents);

        $extension = pathinfo($clientName, PATHINFO_EXTENSION);
        $mimeType = match ($extension) {
            'png' => 'image/png',
            default => 'text/plain',
        };

        return new UploadedFile($tmp, $clientName, $mimeType, null, true);
    }
}
