<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\EmbeddedLines\Fixture;

use Nubit\ApiPlatform\Attribute\EmbeddedLines;

#[EmbeddedLines(parentProperty: 'document', route: '/api/sales_document_lines', normalizationGroups: ['document:read'])]
final class EmbeddedLineFixture {}
