<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine\Traits;

use DateTimeInterface;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/** @phpstan-ignore-next-line trait.unused */
trait SoftDeletableTrait
{
    #[ORM\Column(name: 'deleted_at', type: 'datetime', nullable: true)]
    private ?DateTimeInterface $deletedAt = null;

    public function getDeletedAt(): ?DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTimeInterface $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function softDelete(): static
    {
        $this->deletedAt = new DateTime('now', new DateTimeZone('UTC'));

        return $this;
    }

    public function restore(): static
    {
        $this->deletedAt = null;

        return $this;
    }
}
