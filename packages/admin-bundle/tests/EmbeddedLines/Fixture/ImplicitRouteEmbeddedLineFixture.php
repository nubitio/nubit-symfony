<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\EmbeddedLines\Fixture;

use Nubit\ApiPlatform\Attribute\EmbeddedLines;

#[EmbeddedLines(
    parentProperty: 'document',
    normalizationGroups: ['document:read'],
)]
final class ImplicitRouteEmbeddedLineFixture
{
}