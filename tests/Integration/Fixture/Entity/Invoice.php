<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter;

/**
 * Grid fixture with one column of each type the filter has to survive.
 *
 * The mix is the point. Global search runs a single `LIKE` across every
 * searchable field, and PostgreSQL refuses `LIKE` on numeric, date and boolean
 * columns outright — a resource made only of strings would pass while real
 * ERP resources 500.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_invoice')]
#[ApiResource(operations: [new GetCollection(paginationClientItemsPerPage: true)])]
#[ApiFilter(DataGridFilter::class)]
class Invoice implements FixtureEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    public string $number = '';

    #[ORM\Column(length: 120)]
    public string $customer = '';

    /** Decimal, not float: the column type an ERP actually uses for money. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $total = '0.00';

    #[ORM\Column(type: 'date_immutable')]
    public ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column]
    public bool $paid = false;

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $status = null;

    #[ORM\ManyToOne(targetEntity: GlobalSetting::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?GlobalSetting $currency = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
