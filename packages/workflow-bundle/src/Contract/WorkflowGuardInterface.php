<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Contract;

/**
 * Optional per-transition guard. Implement on the app side for domain rules
 * that cannot be expressed with roles or static {@code from} states.
 */
interface WorkflowGuardInterface
{
    public function canTransition(object $entity, string $transitionName): bool;

    /** Human-readable reason when {@see canTransition} returns false. */
    public function getBlockReason(): ?string;
}