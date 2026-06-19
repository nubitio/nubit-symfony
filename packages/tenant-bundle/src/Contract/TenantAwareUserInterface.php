<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

/**
 * Authenticated users that belong to a tenant. Used by the default
 * {@code user} resolution strategy.
 */
interface TenantAwareUserInterface
{
    public function getTenantId(): ?int;

    public function getTenantName(): ?string;

    public function getTenantDomain(): ?string;
}