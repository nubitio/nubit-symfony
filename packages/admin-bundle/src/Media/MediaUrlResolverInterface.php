<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media;

use Nubit\AdminBundle\Media\Entity\Media;

/**
 * Turns a Media row into the public URL serialized as its `path`.
 *
 * The bundle default routes through `GET /api/media/{id}/file` (streams from
 * any Flysystem storage, same-origin, behind auth). Implement this interface
 * and alias it in services.yaml to emit direct S3/CDN URLs instead:
 *
 *     Nubit\AdminBundle\Media\MediaUrlResolverInterface: '@App\Media\CdnUrlResolver'
 */
interface MediaUrlResolverInterface
{
    public function resolve(Media $media): string;
}
