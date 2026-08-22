<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine\Money;

use Doctrine\ORM\Mapping as ORM;
use Nubit\Platform\Money\Currency;
use Nubit\Platform\Money\Exception\MoneyException;
use Nubit\Platform\Money\Money;

/**
 * The database representation of a {@see Money}: an integer, a currency code
 * and the scale that ties them together.
 *
 * Three columns rather than one string, because an ERP has to `SUM` and compare
 * amounts in SQL — grid totals, ageing reports, credit limits — and none of that
 * works against "19.99 EUR" in a varchar. Storing minor units in a bigint keeps
 * every SQL aggregate exact, since the database only ever sees integers.
 *
 * The scale is stored rather than derived from the code. A row is then
 * self-describing: reading it back never depends on the currency table the
 * application happened to ship at the time, and a currency outside ISO-4217
 * round-trips like any other.
 *
 * ```php
 * #[ORM\Embedded(class: MoneyColumns::class, columnPrefix: 'total_')]
 * #[Ignore]
 * private MoneyColumns $totalColumns;
 *
 * public function getTotal(): ?Money
 * {
 *     return $this->totalColumns->toMoney();
 * }
 *
 * public function setTotal(?Money $total): void
 * {
 *     $this->totalColumns = MoneyColumns::fromMoney($total);
 * }
 * ```
 *
 * Set `columnPrefix` explicitly. Doctrine otherwise derives the column names
 * from the PHP property, so `total_minor_amount` would become
 * `total_columns_minor_amount` and renaming a private property would turn into
 * a migration.
 *
 * Name the embedded property something other than the exposed field — the
 * `Columns` suffix above — and mark it `#[Ignore]`. The serializer resolves a
 * field by looking at the property before the accessors, so an embedded
 * `$total` shadows `getTotal()`, and API Platform ends up trying to hand a
 * MoneyColumns to a method that takes a Money.
 *
 * This is a plain mutable class on purpose: Doctrine hydrates embeddables by
 * writing properties through reflection, which a readonly value object refuses.
 * {@see Money} stays immutable; this is only how it is written down.
 */
#[ORM\Embeddable]
class MoneyColumns
{
    /**
     * bigint, so the column survives amounts a 32-bit integer would not. Doctrine
     * hands bigint back as a string on most platforms, which is why the property
     * is typed loosely and normalised on the way out.
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $minorAmount = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $scale = null;

    public static function fromMoney(?Money $money): self
    {
        $columns = new self();

        if (null === $money) {
            return $columns;
        }

        $columns->minorAmount = $money->minorAmount;
        $columns->currency = $money->currency->code;
        $columns->scale = $money->currency->scale;

        return $columns;
    }

    /**
     * A partially written row — an amount without a currency — is a bug in the
     * application, not a value to guess at, so it is reported rather than
     * defaulted.
     */
    public function toMoney(): ?Money
    {
        if (null === $this->minorAmount || null === $this->currency || null === $this->scale) {
            if (null === $this->minorAmount && null === $this->currency && null === $this->scale) {
                return null;
            }

            throw new MoneyException(
                'A money column is half-written: amount, currency and scale must be set together.',
            );
        }

        return Money::ofMinor((int) $this->minorAmount, Currency::of($this->currency, $this->scale));
    }

    public function isEmpty(): bool
    {
        return null === $this->minorAmount;
    }
}
