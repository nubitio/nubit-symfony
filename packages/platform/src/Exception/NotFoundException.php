<?php

declare(strict_types=1);

namespace Nubit\Platform\Exception;

class NotFoundException extends ServiceException
{
    public static function forResource(string $resource, mixed $identifier): self
    {
        return new self(sprintf('%s with identifier "%s" not found', $resource, $identifier), 404);
    }
}
