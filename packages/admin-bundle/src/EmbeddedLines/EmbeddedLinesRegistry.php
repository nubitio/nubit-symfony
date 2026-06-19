<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\EmbeddedLines;

use Doctrine\Persistence\ManagerRegistry;
use Nubit\ApiPlatform\Attribute\EmbeddedLines;
use Symfony\Component\String\UnicodeString;

final class EmbeddedLinesRegistry
{
    /** @var array<string, EmbeddedLinesDefinition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * @return array<string, EmbeddedLinesDefinition>
     */
    public function all(): array
    {
        return $this->definitions ??= $this->discover();
    }

    public function get(string $key): ?EmbeddedLinesDefinition
    {
        return $this->all()[$key] ?? null;
    }

    public function getByRoutePath(string $path): ?EmbeddedLinesDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->routePath === $path) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return array<string, EmbeddedLinesDefinition>
     */
    private function discover(): array
    {
        $definitions = [];

        foreach ($this->managerRegistry->getManagers() as $manager) {
            foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
                $reflection = new \ReflectionClass($metadata->getName());
                $attributes = $reflection->getAttributes(EmbeddedLines::class);
                if ($attributes === []) {
                    continue;
                }

                $config = $attributes[0]->newInstance();
                $entityClass = $metadata->getName();
                $routePath = $config->route ?? $this->defaultRoutePath($metadata->getTableName());
                $key = $this->routeKey($routePath);
                $parentQueryParam = $config->parentQueryParam ?? $config->parentProperty;

                $definitions[$key] = new EmbeddedLinesDefinition(
                    key: $key,
                    entityClass: $entityClass,
                    routePath: $routePath,
                    routeName: 'nubit_embedded_lines_' . $key,
                    parentProperty: $config->parentProperty,
                    parentQueryParam: $parentQueryParam,
                    normalizationGroups: $config->normalizationGroups,
                );
            }
        }

        return $definitions;
    }

    private function defaultRoutePath(string $tableName): string
    {
        $name = new UnicodeString($tableName);

        if ($name->endsWith('y')) {
            $plural = $name->slice(0, -1)->append('ies')->toString();
        } elseif ($name->endsWith('s')) {
            $plural = $name->append('es')->toString();
        } else {
            $plural = $name->append('s')->toString();
        }

        return '/api/' . $plural;
    }

    private function routeKey(string $routePath): string
    {
        return trim(str_replace('/', '_', $routePath), '_');
    }
}