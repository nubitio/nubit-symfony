<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Event;

use Nubit\WorkflowBundle\Workflow\TransitionDefinition;
use Nubit\WorkflowBundle\Workflow\WorkflowDefinition;
use Symfony\Contracts\EventDispatcher\Event;

final class WorkflowTransitionAppliedEvent extends Event
{
    public function __construct(
        public readonly object $entity,
        public readonly WorkflowDefinition $workflow,
        public readonly TransitionDefinition $transition,
        public readonly string $previousState,
    ) {
    }
}