<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Nubit\ApiPlatform\Attribute\Printable;
use Nubit\ApiPlatform\Doctrine\Money\MoneyColumns;
use Nubit\Platform\Money\Money;
use Nubit\Tests\Integration\Fixture\Document\InvoiceTemplate;
use Symfony\Component\Serializer\Attribute\Ignore;

/** A resource holding money, in the shape an application entity takes. */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_payment')]
#[ApiResource(operations: [new GetCollection(), new Get(), new Post()])]
#[Printable(template: InvoiceTemplate::class, numberProperty: 'reference', title: 'payment.print')]
class Payment implements FixtureEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    public string $reference = '';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $occurredAt = null;

    // Deliberately not named `amount`: the serializer resolves a field from the
    // property before the accessors, so an embedded `$amount` would shadow
    // getAmount() and API Platform would try to store a MoneyColumns.
    #[ORM\Embedded(class: MoneyColumns::class, columnPrefix: 'amount_')]
    #[Ignore]
    private MoneyColumns $amountColumns;

    public function __construct()
    {
        $this->amountColumns = MoneyColumns::fromMoney(null);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAmount(): ?Money
    {
        return $this->amountColumns->toMoney();
    }

    public function setAmount(?Money $amount): void
    {
        $this->amountColumns = MoneyColumns::fromMoney($amount);
    }
}
