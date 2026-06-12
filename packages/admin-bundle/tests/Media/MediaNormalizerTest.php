<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Media;

use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\AdminBundle\Media\MediaUrlResolverInterface;
use Nubit\AdminBundle\Media\Serializer\MediaNormalizer;
use PHPUnit\Framework\TestCase;

final class MediaNormalizerTest extends TestCase
{
    private MediaNormalizer $normalizer;

    protected function setUp(): void
    {
        $resolver = new class implements MediaUrlResolverInterface {
            public function resolve(Media $media): string
            {
                return 'https://cdn.example.test/' . $media->getPath();
            }
        };

        $this->normalizer = new MediaNormalizer($resolver);
    }

    public function testNormalizeEmitsTheResolvedUrlAsPath(): void
    {
        $media = new Media()
            ->setPath('logo-abc123.png')
            ->setOriginalName('logo.png')
            ->setMimeType('image/png')
            ->setSize(123);

        $normalized = $this->normalizer->normalize($media);

        self::assertSame('https://cdn.example.test/logo-abc123.png', $normalized['path']);
        self::assertSame('logo.png', $normalized['originalName']);
        self::assertSame('image/png', $normalized['mimeType']);
        self::assertSame(123, $normalized['size']);
    }

    public function testNormalizeBuildsTheIriFromTheFixedUriTemplate(): void
    {
        $media = new Media()->setPath('x.png');
        \Closure::bind(function (Media $m): void {
            $m->id = 'uuid-1';
        }, null, Media::class)($media);

        $normalized = $this->normalizer->normalize($media);

        self::assertSame('/api/media/uuid-1', $normalized['@id']);
        self::assertSame('uuid-1', $normalized['id']);
    }

    public function testSupportsOnlyMedia(): void
    {
        self::assertTrue($this->normalizer->supportsNormalization(new Media()));
        self::assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testSupportsNormalizationRegardlessOfGroups(): void
    {
        // The whole point of the normalizer: embedding works without the
        // parent's serialization groups being declared on the bundle entity.
        self::assertTrue($this->normalizer->supportsNormalization(
            new Media(),
            'jsonld',
            ['groups' => ['product:read']],
        ));
    }
}
