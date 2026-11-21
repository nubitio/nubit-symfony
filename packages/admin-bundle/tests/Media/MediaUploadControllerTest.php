<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Media;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Nubit\AdminBundle\Media\Controller\MediaUploadController;
use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\AdminBundle\Media\MediaStorage;
use Nubit\AdminBundle\Media\MediaUrlResolverInterface;
use Nubit\AdminBundle\Media\Serializer\MediaNormalizer;
use Nubit\AdminBundle\Security\RouteGuard;
use Nubit\Platform\Filesystem\FileManager;
use Nubit\Platform\Tenant\Context\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** `media.upload_security`: who may upload, decided by the application, before anything is stored. */
final class MediaUploadControllerTest extends TestCase
{
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
    }

    public function testWithoutAnExpressionTheUploadIsStoredAsBefore(): void
    {
        $response = $this->controller(guard: null, expression: null)($this->request());

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(1, $this->filesystem->listContents('media')->toArray());
    }

    public function testAnExpressionTheUserSatisfiesLetsTheUploadThrough(): void
    {
        $response = $this->controller(
            guard: new RouteGuard($this->checkerReturning(true)),
            expression: "is_granted('catalog.edit')",
        )($this->request());

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testAnExpressionTheUserDoesNotSatisfyStopsTheUploadBeforeStoringAnything(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $controller = $this->controller(
            guard: new RouteGuard($this->checkerReturning(false)),
            expression: "is_granted('catalog.edit')",
            entityManager: $entityManager,
        );

        try {
            $controller($this->request());
            $this->fail('the upload should have been refused');
        } catch (AccessDeniedHttpException) {
            $this->assertSame([], $this->filesystem->listContents('media')->toArray());
        }
    }

    private function controller(
        ?RouteGuard $guard,
        ?string $expression,
        ?EntityManagerInterface $entityManager = null,
    ): MediaUploadController {
        $storage = new MediaStorage(
            new FileManager($this->filesystem, new TenantContext(), new AsciiSlugger()),
            'media',
        );
        $resolver = new class implements MediaUrlResolverInterface {
            public function resolve(Media $media): string
            {
                return '/files/' . $media->getPath();
            }
        };

        return new MediaUploadController(
            $storage,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            new MediaNormalizer($resolver),
            $guard,
            $expression,
        );
    }

    private function request(): Request
    {
        $path = tempnam(sys_get_temp_dir(), 'nubit_upload_');
        $this->assertNotFalse($path);
        file_put_contents($path, 'bytes');

        return Request::create('/api/media', 'POST', files: ['file' => new UploadedFile(
            $path,
            'logo.txt',
            'text/plain',
            null,
            true,
        )]);
    }

    private function checkerReturning(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }
}
