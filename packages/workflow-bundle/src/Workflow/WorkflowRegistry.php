<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Workflow;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\String\UnicodeString;

final class WorkflowRegistry
{
    /** @var array<string, WorkflowDefinition>|null */
    private ?array $byRouteKey = null;

    /** @var array<string, WorkflowDefinition>|null */
    private ?array $byEntityClass = null;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        private readonly WorkflowMetadata $metadata,
        private readonly string $apiRoutePrefix = '/api',
    ) {
    }

    /**
     * @return array<string, WorkflowDefinition>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->byRouteKey ?? [];
    }

    public function getByRouteKey(string $routeKey): ?WorkflowDefinition
    {
        $this->ensureLoaded();

        return $this->byRouteKey[$routeKey] ?? null;
    }

    public function getByEntityClass(string $entityClass): ?WorkflowDefinition
    {
        $this->ensureLoaded();

        return $this->byEntityClass[$entityClass] ?? null;
    }

    public function getByRoutePrefix(string $routePrefix): ?WorkflowDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->routePrefix === $routePrefix) {
                return $definition;
            }
        }

        return null;
    }

    private function ensureLoaded(): void
    {
        if (null !== $this->byRouteKey) {
            return;
        }

        $byRouteKey = [];
        $byEntityClass = [];

        foreach ($this->managerRegistry->getManagers() as $manager) {
            foreach ($manager->getMetadataFactory()->getAllMetadata() as $entityMetadata) {
                $entityClass = $entityMetadata->getName();
                $workflow = $this->metadata->read($entityClass);
                if (null === $workflow || $workflow->transitions === []) {
                    continue;
                }

                $routePrefix = $workflow->routePrefix ?? $this->inferRoutePrefix($entityClass);
                $routeKey = $this->routeKey($routePrefix);
                $definition = new WorkflowDefinition(
                    entityClass: $entityClass,
                    field: $workflow->field,
                    routePrefix: $routePrefix,
                    routeKey: $routeKey,
                    transitions: $this->metadata->buildTransitions($workflow->transitions),
                );

                $byRouteKey[$routeKey] = $definition;
                $byEntityClass[$entityClass] = $definition;
            }
        }

        $this->byRouteKey = $byRouteKey;
        $this->byEntityClass = $byEntityClass;
    }

    private function inferRoutePrefix(string $entityClass): string
    {
        try {
            $collection = $this->resourceMetadataFactory->create($entityClass);
            foreach ($collection as $operation) {
                if ($operation instanceof GetCollection) {
                    $template = $operation->getUriTemplate();
                    if (\is_string($template) && $template !== '') {
                        return $this->apiRoutePrefix . (str_starts_with($template, '/') ? $template : '/' . $template);
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to table-name heuristic.
        }

        $short = (new \ReflectionClass($entityClass))->getShortName();

        return $this->apiRoutePrefix . '/' . ((new UnicodeString($short))->snake())->toString() . 's';
    }

    private function routeKey(string $routePrefix): string
    {
        return trim(str_replace('/', '_', $routePrefix), '_');
    }
}
