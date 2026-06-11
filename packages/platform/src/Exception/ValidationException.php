<?php

declare(strict_types=1);

namespace Nubit\Platform\Exception;

class ValidationException extends ServiceException
{
    /** @var array<string, array<string>> */
    private array $errors = [];

    /** @param array<string, array<string>> $errors */
    public static function withErrors(array $errors): self
    {
        $exception = new self('Validation failed');
        $exception->errors = $errors;

        return $exception;
    }

    /** @return array<string, array<string>> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
