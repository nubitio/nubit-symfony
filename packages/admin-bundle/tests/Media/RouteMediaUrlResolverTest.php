<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Media;

use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\AdminBundle\Media\RouteMediaUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RouteMediaUrlResolverTest extends TestCase
{
    public function testResolvesThroughTheStreamingRoute(): void
    {
        $media = new Media();
        \Closure::bind(function (Media $m): void {
            $m->id = 'uuid-9';
        }, null, Media::class)($media);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('nubit_admin_media_file', ['id' => 'uuid-9'])
            ->willReturn('/api/media/uuid-9/file');

        $resolver = new RouteMediaUrlResolver($urlGenerator);

        self::assertSame('/api/media/uuid-9/file', $resolver->resolve($media));
    }
}
