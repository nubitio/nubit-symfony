<?php

declare(strict_types=1);

namespace Nubit\Platform\Money;

use Nubit\Platform\Money\Exception\MoneyException;
use Nubit\Platform\Money\Internal\Decimal;
use Nubit\Platform\Money\Internal\IntegerMath;

/**
 * An exact monetary amount, held as an integer count of minor units.
 *
 * Floats cannot represent 0.1, so a total assembled from them drifts — slowly,
 * invisibly, and then all at once when someone reconciles a ledger. Everything
 * here stays in integers, every operation that can lose precision demands a
 * {@see RoundingMode}, and arithmetic across two currencies is refused rather
 * than guessed at.
 *
 * ```php
 * $unit  = Money::of('19.99', 'EUR');
 * $line  = $unit->multipliedBy(3);                       // 59.97 EUR, exact
 * $tax   = $line->multipliedBy('0.21', RoundingMode::HalfUp);
 * $total = $line->plus($tax);
 * ```
 */
final readonly class Money implements \Stringable, \JsonSerializable
{
    private function __construct(
        /** The amount in minor units: 1999 for 19.99 EUR, 1999 for ¥1999. */
        public int $minorAmount,
        public Currency $currency,
    ) {}

    /**
     * Builds from a decimal literal in major units — "19.99", "-4", "0.005".
     *
     * A literal with more decimals than the currency has is a question the
     * caller has to answer, so it needs a rounding mode; without one it is
     * refused rather than quietly truncated.
     */
    public static function of(
        string|int $amount,
        Currency|string $currency,
        RoundingMode $roundingMode = RoundingMode::Unnecessary,
    ): self {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);
        $decimal = Decimal::parse((string) $amount);

        if ($decimal->scale <= $currency->scale) {
            return new self($decimal->scaleUp($currency->scale)->unscaled, $currency);
        }

        $divisor = IntegerMath::powerOfTen($decimal->scale - $currency->scale);

        return new self(IntegerMath::divide($decimal->unscaled, $divisor, $roundingMode), $currency);
    }

    /** Builds from a count of minor units — 1999 for 19.99 EUR. */
    public static function ofMinor(int $minorAmount, Currency|string $currency): self
    {
        return new self($minorAmount, $currency instanceof Currency ? $currency : Currency::of($currency));
    }

    public static function zero(Currency|string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(IntegerMath::add($this->minorAmount, $other->minorAmount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(IntegerMath::subtract($this->minorAmount, $other->minorAmount), $this->currency);
    }

    /**
     * Multiplies by a quantity or a rate.
     *
     * An integer factor — three of an item — is always exact. A decimal factor —
     * a tax rate, a discount — usually is not, which is why the rounding mode is
     * demanded up front instead of assumed.
     */
    public function multipliedBy(string|int $factor, RoundingMode $roundingMode = RoundingMode::Unnecessary): self
    {
        if (is_int($factor)) {
            return new self(IntegerMath::multiply($this->minorAmount, $factor), $this->currency);
        }

        $decimal = Decimal::parse($factor);
        $product = IntegerMath::multiply($this->minorAmount, $decimal->unscaled);
        $divisor = IntegerMath::powerOfTen($decimal->scale);

        return new self(IntegerMath::divide($product, $divisor, $roundingMode), $this->currency);
    }

    public function dividedBy(string|int $divisor, RoundingMode $roundingMode = RoundingMode::Unnecessary): self
    {
        $decimal = Decimal::parse((string) $divisor);

        if (0 === $decimal->unscaled) {
            throw new MoneyException('Cannot divide a monetary amount by zero.');
        }

        // Scale the dividend by the divisor's own scale first, so dividing by
        // "0.5" doubles the amount instead of collapsing to a truncated zero.
        $dividend = IntegerMath::multiply($this->minorAmount, IntegerMath::powerOfTen($decimal->scale));

        return new self(IntegerMath::divide($dividend, $decimal->unscaled, $roundingMode), $this->currency);
    }

    /**
     * Splits the amount across the given weights without losing or inventing a
     * minor unit.
     *
     * Dividing a bill three ways and rounding each share independently produces
     * a total that no longer matches the bill. The remainder is handed out one
     * unit at a time instead, largest weight first, so the parts always sum back
     * to the whole.
     *
     * @param list<int> $weights
     *
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        if ([] === $weights) {
            throw new MoneyException('Allocation needs at least one weight.');
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new MoneyException('Allocation weights cannot be negative.');
            }
        }

        $total = array_reduce($weights, IntegerMath::add(...), 0);
        if (0 === $total) {
            throw new MoneyException('Allocation weights cannot all be zero.');
        }

        $shares = [];
        $distributed = 0;

        foreach ($weights as $weight) {
            $share = IntegerMath::divide(
                IntegerMath::multiply($this->minorAmount, $weight),
                $total,
                RoundingMode::Down,
            );
            $shares[] = $share;
            $distributed = IntegerMath::add($distributed, $share);
        }

        $remainder = IntegerMath::subtract($this->minorAmount, $distributed);
        $step = $remainder < 0 ? -1 : 1;

        // Truncation left something over. Hand it out to the largest weights
        // first, which is the convention invoices and tax rules expect.
        $order = array_keys($weights);
        usort($order, static fn(int $a, int $b): int => $weights[$b] <=> $weights[$a] ?: $a <=> $b);

        $position = 0;
        while (0 !== $remainder && [] !== $order) {
            $index = $order[$position % count($order)];
            $shares[$index] = IntegerMath::add($shares[$index], $step);
            $remainder = IntegerMath::subtract($remainder, $step);
            ++$position;
        }

        return array_map(fn(int $share): self => new self($share, $this->currency), $shares);
    }

    /**
     * Sums a list, refusing an empty one.
     *
     * An empty sum has no currency, and inventing one would be the first step
     * toward a total denominated in the wrong money.
     *
     * @param list<self> $amounts
     */
    public static function sum(array $amounts): self
    {
        $total = array_shift($amounts);
        if (null === $total) {
            throw new MoneyException('Cannot sum an empty list: the currency would be unknown.');
        }

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /** @param list<self> $amounts */
    public static function sumOrZero(array $amounts, Currency|string $currency): self
    {
        return [] === $amounts ? self::zero($currency) : self::sum($amounts);
    }

    public function negated(): self
    {
        return new self(IntegerMath::negate($this->minorAmount), $this->currency);
    }

    public function absolute(): self
    {
        return new self(IntegerMath::absolute($this->minorAmount), $this->currency);
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorAmount <=> $other->minorAmount;
    }

    public function isEqualTo(self $other): bool
    {
        return $this->currency->is($other->currency) && $this->minorAmount === $other->minorAmount;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return 0 === $this->minorAmount;
    }

    public function isNegative(): bool
    {
        return $this->minorAmount < 0;
    }

    public function isPositive(): bool
    {
        return $this->minorAmount > 0;
    }

    /** The amount as a decimal literal in major units — "19.99", "-4.00", "1999". */
    public function toDecimalString(): string
    {
        return Decimal::format($this->minorAmount, $this->currency->scale);
    }

    public function __toString(): string
    {
        return $this->toDecimalString() . ' ' . $this->currency->code;
    }

    /**
     * The wire shape the frontend and the API documentation agree on.
     *
     * The amount travels as a string, not a number: JSON numbers are IEEE-754
     * doubles in every JavaScript runtime, and handing 1234567.89 to the client
     * as a number reintroduces exactly the drift this class removes.
     *
     * @return array{amount: string, currency: string, scale: int, minorAmount: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->toDecimalString(),
            'currency' => $this->currency->code,
            'scale' => $this->currency->scale,
            'minorAmount' => $this->minorAmount,
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$this->currency->is($other->currency)) {
            throw new MoneyException(sprintf(
                'Cannot combine %s and %s: convert one of them first.',
                $this->currency->code,
                $other->currency->code,
            ));
        }
    }
}
