<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nubit\TenantBundle\Attribute\TenantScoped;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Entity\TenantOwnedTrait;

/**
 * Child of {@see Widget}. Exists to prove the filter also constrains entities
 * reached through a JOIN — the leak path a root-only test would miss.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_widget_part')]
#[TenantScoped]
class WidgetPart implements TenantOwnedInterface, FixtureEntity
{
    use TenantOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: Widget::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Widget $widget = null;

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

    public function getWidget(): ?Widget
    {
        return $this->widget;
    }

    public function setWidget(?Widget $widget): static
    {
        $this->widget = $widget;

        return $this;
    }
}
