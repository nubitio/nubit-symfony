<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Neither {@see \Nubit\TenantBundle\Attribute\TenantScoped} nor
 * {@see \Nubit\TenantBundle\Contract\TenantOwnedInterface}, and not on the
 * unscoped allowlist — the developer simply forgot.
 *
 * The filter must fail closed for this entity. A test that only proves scoped
 * entities are filtered would never catch a regression that turns the forgotten
 * case into a silent cross-tenant read.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_loose_note')]
class LooseNote implements FixtureEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(nullable: true)]
    private ?int $tenantId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

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
