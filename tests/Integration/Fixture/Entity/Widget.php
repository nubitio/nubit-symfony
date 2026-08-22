<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nubit\TenantBundle\Attribute\TenantScoped;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Entity\TenantOwnedTrait;

/** Ordinary tenant-owned aggregate root: the shape most application entities take. */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_widget')]
#[TenantScoped]
class Widget implements TenantOwnedInterface, FixtureEntity
{
    use TenantOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

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
}
