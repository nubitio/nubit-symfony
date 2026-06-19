<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Workflow;

use Nubit\WorkflowBundle\Contract\WorkflowGuardInterface;

final readonly class TransitionDefinition
{
    /**
     * @param list<string>                              $from
     * @param list<string>                              $roles
     * @param class-string<WorkflowGuardInterface>|null $guard
     * @param array<string, mixed>                      $set
     */
    public function __construct(
        public string $name,
        public array $from,
        public string $to,
        public ?string $label = null,
        public array $roles = [],
        public ?string $guard = null,
        public array $set = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toOpenApi(): array
    {
        return array_filter([
            'name' => $this->name,
            'from' => $this->from,
            'to' => $this->to,
            'label' => $this->label,
            'roles' => $this->roles !== [] ? $this->roles : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}