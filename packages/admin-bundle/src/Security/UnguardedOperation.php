<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Security;

final readonly class UnguardedOperation
{
    public function __construct(
        public string $resourceClass,
        public string $resourceShortName,
        public string $method,
        public ?string $uriTemplate,
    ) {
    }
}
