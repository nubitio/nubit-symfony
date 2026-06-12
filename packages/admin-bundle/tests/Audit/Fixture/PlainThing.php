<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Audit\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class PlainThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(length: 100)]
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
