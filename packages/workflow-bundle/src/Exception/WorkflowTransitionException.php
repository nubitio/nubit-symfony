<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class WorkflowTransitionException extends HttpException
{
    public static function notFound(string $transition): self
    {
        return new self(404, sprintf('Workflow transition "%s" is not defined.', $transition));
    }

    public static function forbidden(string $reason): self
    {
        return new self(403, $reason);
    }

    public static function invalidState(string $transition, string $current, string $field): self
    {
        return new self(422, sprintf(
            'Transition "%s" is not allowed when %s is "%s".',
            $transition,
            $field,
            $current,
        ));
    }
}