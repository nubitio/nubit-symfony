<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Symfony\Component\Serializer\Attribute\Ignore;

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