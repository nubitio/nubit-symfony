<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * Default implementation of {@see TenantOwnedInterface}.
 *
 * @see \Nubit\TenantBundle\Entity\TenantOwnedTrait for the tenant-bundle
 *      alias kept for source compatibility — use this one directly in new
 *      code.
 */
trait TenantOwnedTrait
{
    #[ORM\Column(nullable: true)]
    #[Ignore]
    private ?int $tenantId = null;

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(?int $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }
}
