<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Nubit\ApiPlatform\Attribute\Importable;
use Nubit\ApiPlatform\Doctrine\Money\MoneyColumns;
use Nubit\Platform\Money\Money;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shape a real import target takes: a natural key, money, a date, a
 * boolean, and a validated field.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_imported_product')]
#[ORM\UniqueConstraint(name: 'UNIQ_FIXTURE_PRODUCT_SKU', columns: ['sku'])]
#[ApiResource(operations: [new GetCollection()])]
#[Importable(
    fields: ['sku', 'name', 'price', 'active', 'launchedAt', 'stock'],
    naturalKey: ['sku'],
    required: ['sku', 'name'],
    batchSize: 2,
)]
class ImportedProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    public string $sku = '';

    #[ORM\Column(length: 160)]
    #[Assert\Length(max: 160, min: 2)]
    public string $name = '';

    #[ORM\Embedded(class: MoneyColumns::class, columnPrefix: 'price_')]
    #[Ignore]
    private MoneyColumns $priceColumns;

    #[ORM\Column]
    public bool $active = false;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $launchedAt = null;

    #[ORM\Column(nullable: true)]
    public ?int $stock = null;

    public function __construct()
    {
        $this->priceColumns = MoneyColumns::fromMoney(null);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrice(): ?Money
    {
        return $this->priceColumns->toMoney();
    }

    public function setPrice(?Money $price): void
    {
        $this->priceColumns = MoneyColumns::fromMoney($price);
    }
}
