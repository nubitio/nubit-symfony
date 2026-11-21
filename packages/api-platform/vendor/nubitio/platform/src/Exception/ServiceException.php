<?php

declare(strict_types=1);

namespace Nubit\Platform\Exception;

use RuntimeException;
use Throwable;

class ServiceException extends RuntimeException
{
    public static function create(string $message, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message, $code, $previous);
    }
}
