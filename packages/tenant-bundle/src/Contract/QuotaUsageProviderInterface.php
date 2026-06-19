<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

interface QuotaUsageProviderInterface
{
    public function supports(string $resource): bool;

    public function count(string $resource): int;
}