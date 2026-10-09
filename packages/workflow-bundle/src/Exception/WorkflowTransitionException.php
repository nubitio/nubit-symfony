<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Exception;

use Nubit\Platform\Exception\ServiceException;

/**
 * Extends ServiceException (not HttpException) so Nubit\ApiPlatform\Http\
 * ExceptionListener recognizes it as a domain exception whose message is
 * safe to expose to the client — otherwise, with APP_DEBUG=false, the API
 * response collapses to the generic HTTP reason phrase ("Forbidden") and
 * the guard's actual block reason (e.g. "Falta la foto del pesaje en
 * balanza.") never reaches the frontend.
 */
final class WorkflowTransitionException extends ServiceException
{
    public static function notFound(string $transition): self
    {
        return new self(sprintf('Workflow transition "%s" is not defined.', $transition), 404);
    }

    public static function forbidden(string $reason): self
    {
        return new self($reason, 403);
    }

    public static function invalidState(string $transition, string $current, string $field): self
    {
        return new self(sprintf('Transition "%s" is not allowed when %s is "%s".', $transition, $field, $current), 422);
    }
}
