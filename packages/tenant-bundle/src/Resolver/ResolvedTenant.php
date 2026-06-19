<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

final readonly class ResolvedTenant
{
    public function __construct(
        public int $id,
        public ?string $name = null,
        public ?string $domain = null,
    ) {
    }
}