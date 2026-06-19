<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nubit_sequence_counter')]
#[ORM\UniqueConstraint(name: 'UNIQ_SEQUENCE_SCOPE_NAME', columns: ['scope_key', 'name'])]
class SequenceCounter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 190)]
    private string $scopeKey = '';

    #[ORM\Column(length: 64)]
    private string $name = 'default';

    #[ORM\Column]
    private int $nextValue = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    public function setScopeKey(string $scopeKey): static
    {
        $this->scopeKey = $scopeKey;

        return $this;
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

    public function getNextValue(): int
    {
        return $this->nextValue;
    }

    public function setNextValue(int $nextValue): static
    {
        $this->nextValue = $nextValue;

        return $this;
    }

    public function allocate(): int
    {
        $current = $this->nextValue;
        ++$this->nextValue;

        return $current;
    }
}