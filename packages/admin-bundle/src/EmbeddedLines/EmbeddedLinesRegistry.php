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

                if (!$metadata instanceof \Doctrine\ORM\Mapping\ClassMetadata) {
                    continue;
                }

                $config = $attributes[0]->newInstance();
                $entityClass = $metadata->getName();
                $routePath = $config->route ?? $this->defaultRoutePath($metadata->getTableName());

                if ($config->route === null) {
                    trigger_deprecation(
                        'nubitio/admin-bundle',
                        '0.6.0',
                        'Omitting the route on #[EmbeddedLines] for "%s" is deprecated; set route: "%s" explicitly.',
                        $entityClass,
                        $routePath,
                    );
                }
                $key = $this->routeKey($routePath);
                $parentQueryParam = $config->parentQueryParam ?? $config->parentProperty;

                $parentBinding = $this->resolveParentBinding($metadata, $config->parentProperty);

                $definitions[$key] = new EmbeddedLinesDefinition(
                    key: $key,
                    entityClass: $entityClass,
                    routePath: $routePath,
                    routeName: 'nubit_embedded_lines_' . $key,
                    parentProperty: $config->parentProperty,
                    parentQueryParam: $parentQueryParam,
                    normalizationGroups: $config->normalizationGroups,
                    parentEntityClass: $parentBinding['parentEntityClass'],
                    collectionProperty: $parentBinding['collectionProperty'],
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

    /**
     * @return array{parentEntityClass: string, collectionProperty: string}
     */
    private function resolveParentBinding(\Doctrine\ORM\Mapping\ClassMetadata $lineMetadata, string $parentProperty): array
    {
        if (!$lineMetadata->hasAssociation($parentProperty)) {
            return ['parentEntityClass' => '', 'collectionProperty' => $parentProperty];
        }

        $mapping = $lineMetadata->getAssociationMapping($parentProperty);
        $parentEntityClass = $mapping['targetEntity'] ?? '';
        $collectionProperty = $mapping['inversedBy'] ?? $parentProperty;

        return [
            'parentEntityClass' => \is_string($parentEntityClass) ? $parentEntityClass : '',
            'collectionProperty' => \is_string($collectionProperty) ? $collectionProperty : $parentProperty,
        ];
    }

    private function routeKey(string $routePath): string
    {
        return trim(str_replace('/', '_', $routePath), '_');
    }
}