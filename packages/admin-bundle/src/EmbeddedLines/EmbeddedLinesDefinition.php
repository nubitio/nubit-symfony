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
        public string $parentEntityClass,
        public string $collectionProperty,
    ) {
    }

    /** @return array<string, mixed> */
    public function toOpenApi(): array
    {
        $short = static fn (string $class): string => substr($class, strrpos($class, '\\') + 1);

        return [
            'propertyName' => $this->collectionProperty,
            'lineClass' => $short($this->entityClass),
            'lineEntityClass' => $this->entityClass,
            'routePath' => $this->routePath,
            'parentQueryParam' => $this->parentQueryParam,
            'reloadUrl' => $this->routePath . '?' . $this->parentQueryParam . '={id}',
        ];
    }
}