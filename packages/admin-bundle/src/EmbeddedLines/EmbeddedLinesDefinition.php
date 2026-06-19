<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\EmbeddedLines;

/**
 * @internal Built by {@see EmbeddedLinesRegistry}.
 */
final readonly class EmbeddedLinesDefinition
{
    public function __construct(
        public string $key,
        public string $entityClass,
        public string $routePath,
        public string $routeName,
        public string $parentProperty,
        public string $parentQueryParam,
        /** @var list<string> */
        public array $normalizationGroups,
    ) {
    }
}