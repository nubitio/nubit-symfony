<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine\Traits;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/** @phpstan-ignore-next-line trait.unused */
trait TimestampableTrait
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function updateTimestampsOnPersist(): void
    {
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    #[ORM\PreUpdate]
    public function updateTimestampsOnUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
