<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Nubit\ApiPlatform\Attribute\Authorized;
use Nubit\ApiPlatform\Attribute\RowScoped;
use Nubit\ApiPlatform\Doctrine\Money\MoneyColumns;
use Nubit\Platform\Money\Money;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * A resource that is both row-scoped and carries a limited domain action —
 * the two things a real ERP permission model has to handle at once.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_stock_movement')]
#[ApiResource(operations: [new GetCollection(), new Get(), new Post(), new Delete()])]
#[RowScoped(field: 'warehouse', claim: 'warehouses')]
#[Authorized(resource: 'movement', actions: ['approve'], limited: ['approve' => 'value'])]
class StockMovement implements FixtureEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    public string $reference = '';

    #[ORM\Column]
    public int $warehouse = 0;

    #[ORM\Embedded(class: MoneyColumns::class, columnPrefix: 'value_')]
    #[Ignore]
    private MoneyColumns $valueColumns;

    public function __construct()
    {
        $this->valueColumns = MoneyColumns::fromMoney(null);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getValue(): ?Money
    {
        return $this->valueColumns->toMoney();
    }

    public function setValue(?Money $value): void
    {
        $this->valueColumns = MoneyColumns::fromMoney($value);
    }
}
