<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

/**
 * Rows owned by a tenant. Reads are scoped by {@see \Nubit\TenantBundle\Doctrine\Filter\TenantFilter};
 * writes are stamped by {@see \Nubit\TenantBundle\EventListener\TenantStampListener}.
 */
interface TenantOwnedInterface
{
    public function getTenantId(): ?int;

    public function setTenantId(?int $tenantId): static;
}