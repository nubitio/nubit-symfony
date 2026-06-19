<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Workflow;

use Nubit\WorkflowBundle\Attribute\Workflow;
use Nubit\WorkflowBundle\Contract\WorkflowGuardInterface;
use ReflectionClass;

final class WorkflowMetadata
{
    public function read(string $entityClass): ?Workflow
    {
        $reflection = new ReflectionClass($entityClass);
        $attributes = $reflection->getAttributes(Workflow::class);

        return $attributes !== [] ? $attributes[0]->newInstance() : null;
    }

    /**
     * @param array<string, array{
     *     from: list<string>,
     *     to: string,
     *     label?: string,
     *     roles?: list<string>,
     *     guard?: class-string<WorkflowGuardInterface>,
     *     set?: array<string, mixed>,
     * }> $transitions
     *
     * @return list<TransitionDefinition>
     */
    public function buildTransitions(array $transitions): array
    {
        $definitions = [];

        foreach ($transitions as $name => $config) {
            $definitions[] = new TransitionDefinition(
                name: (string) $name,
                from: array_values($config['from'] ?? []),
                to: (string) ($config['to'] ?? ''),
                label: $config['label'] ?? null,
                roles: array_values($config['roles'] ?? []),
                guard: $config['guard'] ?? null,
                set: $config['set'] ?? [],
            );
        }

        return $definitions;
    }
}