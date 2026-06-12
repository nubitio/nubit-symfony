<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media;

use Nubit\AdminBundle\Media\Entity\Media;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Default resolver: the bundle's own streaming endpoint. Storage-agnostic
 * (local disk, S3, memory — anything Flysystem) and protected by the same
 * firewall as the rest of /api, at the cost of proxying bytes through PHP.
 */
final readonly class RouteMediaUrlResolver implements MediaUrlResolverInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function resolve(Media $media): string
    {
        return $this->urlGenerator->generate('nubit_admin_media_file', ['id' => $media->getId()]);
    }
}
