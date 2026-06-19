<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Workflow;

final readonly class WorkflowDefinition
{
    /**
     * @param list<TransitionDefinition> $transitions
     */
    public function __construct(
        public string $entityClass,
        public string $field,
        public string $routePrefix,
        public string $routeKey,
        public array $transitions,
    ) {
    }

    public function findTransition(string $name): ?TransitionDefinition
    {
        foreach ($this->transitions as $transition) {
            if ($transition->name === $name) {
                return $transition;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toOpenApi(): array
    {
        return [
            'field' => $this->field,
            'transitions' => array_map(
                static fn (TransitionDefinition $transition): array => $transition->toOpenApi(),
                $this->transitions,
            ),
        ];
    }
}