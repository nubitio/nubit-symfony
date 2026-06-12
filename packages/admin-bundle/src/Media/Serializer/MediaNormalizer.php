<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media\Serializer;

use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\AdminBundle\Media\MediaUrlResolverInterface;
use Override;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Serializes Media everywhere it appears — top-level or embedded in any
 * parent resource — WITHOUT serialization groups: parents would otherwise
 * have to add their own groups to the bundle entity's properties, which they
 * can't. `path` is always the resolved public URL, which is what
 * fileField()/imageField() in @nubitio/react-admin render.
 */
final readonly class MediaNormalizer implements NormalizerInterface
{
    public function __construct(
        private MediaUrlResolverInterface $urlResolver,
    ) {
    }

    /**
     * @param Media $data
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return [
            '@id' => '/api/media/' . $data->getId(),
            'id' => $data->getId(),
            'path' => $this->urlResolver->resolve($data),
            'originalName' => $data->getOriginalName(),
            'mimeType' => $data->getMimeType(),
            'size' => $data->getSize(),
        ];
    }

    #[Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Media;
    }

    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [Media::class => true];
    }
}
