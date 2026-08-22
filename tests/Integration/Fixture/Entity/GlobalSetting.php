<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Infrastructure row that is deliberately global — country codes, tax tables,
 * currency rates. Visible to every tenant, but only because it is named
 * explicitly in {@code nubit_tenant.unscoped_entities}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_global_setting')]
class GlobalSetting implements FixtureEntity
{
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
