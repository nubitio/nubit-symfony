<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Money;

use Nubit\Platform\Money\Currency;
use Nubit\Platform\Money\Exception\MoneyException;
use Nubit\Platform\Money\Money;
use Nubit\Platform\Money\RoundingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
#[CoversClass(Currency::class)]
final class MoneyTest extends TestCase
{
    public function testDecimalLiteralsBecomeMinorUnits(): void
    {
        self::assertSame(1999, Money::of('19.99', 'EUR')->minorAmount);
        self::assertSame(-450, Money::of('-4.50', 'EUR')->minorAmount);
        self::assertSame(400, Money::of('4', 'EUR')->minorAmount);
        self::assertSame(0, Money::of('0.00', 'EUR')->minorAmount);
    }

    /** The classic float failure: 0.1 + 0.2 is not 0.3 in binary floating point. */
    public function testTenthsAddUpExactly(): void
    {
        $sum = Money::of('0.10', 'EUR')->plus(Money::of('0.20', 'EUR'));

        self::assertSame('0.30', $sum->toDecimalString());
        self::assertTrue($sum->isEqualTo(Money::of('0.30', 'EUR')));
    }

    /** A currency with no minor unit must not be silently multiplied by a hundred. */
    public function testCurrencyScaleIsRespected(): void
    {
        self::assertSame(1999, Money::of('1999', 'JPY')->minorAmount);
        self::assertSame('1999', Money::of('1999', 'JPY')->toDecimalString());

        self::assertSame(1999000, Money::of('1999', 'KWD')->minorAmount);
        self::assertSame('1999.000', Money::of('1999', 'KWD')->toDecimalString());
    }

    public function testMoreDecimalsThanTheCurrencyHasIsRefusedWithoutARoundingMode(): void
    {
        $this->expectException(MoneyException::class);
        Money::of('19.999', 'EUR');
    }

    public function testMoreDecimalsThanTheCurrencyHasIsAllowedWithARoundingMode(): void
    {
        self::assertSame('20.00', Money::of('19.999', 'EUR', RoundingMode::HalfUp)->toDecimalString());
        self::assertSame('19.99', Money::of('19.999', 'EUR', RoundingMode::Down)->toDecimalString());
    }

    public function testIntegerMultiplicationIsExactAndNeedsNoRounding(): void
    {
        self::assertSame('59.97', Money::of('19.99', 'EUR')->multipliedBy(3)->toDecimalString());
    }

    public function testRateMultiplicationRequiresARoundingMode(): void
    {
        $this->expectException(MoneyException::class);
        Money::of('59.97', 'EUR')->multipliedBy('0.21');
    }

    public function testRateMultiplication(): void
    {
        // 59.97 * 0.21 = 12.5937 → 12.59
        self::assertSame(
            '12.59',
            Money::of('59.97', 'EUR')->multipliedBy('0.21', RoundingMode::HalfUp)->toDecimalString(),
        );
    }

    public function testDivisionByADecimalScalesRatherThanTruncates(): void
    {
        self::assertSame('20.00', Money::of('10.00', 'EUR')->dividedBy('0.5')->toDecimalString());
        self::assertSame('3.33', Money::of('10.00', 'EUR')->dividedBy(3, RoundingMode::HalfUp)->toDecimalString());
    }

    /** @return iterable<string, array{string, RoundingMode, string}> */
    public static function roundingCases(): iterable
    {
        // 1.005 sits exactly on the halfway point at two decimals.
        yield 'half up on a tie' => ['1.005', RoundingMode::HalfUp, '1.01'];
        yield 'half down on a tie' => ['1.005', RoundingMode::HalfDown, '1.00'];
        yield 'half even to the even neighbour' => ['1.005', RoundingMode::HalfEven, '1.00'];
        yield 'half even the other way' => ['1.015', RoundingMode::HalfEven, '1.02'];
        yield 'up' => ['1.001', RoundingMode::Up, '1.01'];
        yield 'down' => ['1.009', RoundingMode::Down, '1.00'];
        yield 'ceiling on a positive' => ['1.001', RoundingMode::Ceiling, '1.01'];
        yield 'ceiling on a negative' => ['-1.009', RoundingMode::Ceiling, '-1.00'];
        yield 'floor on a positive' => ['1.009', RoundingMode::Floor, '1.00'];
        yield 'floor on a negative' => ['-1.001', RoundingMode::Floor, '-1.01'];
        yield 'half up on a negative tie' => ['-1.005', RoundingMode::HalfUp, '-1.01'];
    }

    #[DataProvider('roundingCases')]
    public function testRoundingModes(string $literal, RoundingMode $mode, string $expected): void
    {
        self::assertSame($expected, Money::of($literal, 'EUR', $mode)->toDecimalString());
    }

    public function testMixingCurrenciesIsRefused(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessage('Cannot combine EUR and USD');

        Money::of('1.00', 'EUR')->plus(Money::of('1.00', 'USD'));
    }

    public function testAmountsInDifferentCurrenciesAreNeverEqual(): void
    {
        self::assertFalse(Money::of('1.00', 'EUR')->isEqualTo(Money::of('1.00', 'USD')));
    }

    /** @return iterable<string, array{string, list<int>, list<string>}> */
    public static function allocationCases(): iterable
    {
        yield 'a cent that will not divide by three' => ['0.01', [1, 1, 1], ['0.01', '0.00', '0.00']];
        yield 'ten cents three ways' => ['0.10', [1, 1, 1], ['0.04', '0.03', '0.03']];
        yield 'weighted split' => ['1.00', [70, 30], ['0.70', '0.30']];
        // 1.5 and 3.5 cents both truncate; the spare cent goes to the larger
        // weight, which is what makes the rule independent of argument order.
        yield 'uneven weighted split' => ['0.05', [3, 7], ['0.01', '0.04']];
        yield 'a negative amount' => ['-0.10', [1, 1, 1], ['-0.04', '-0.03', '-0.03']];
    }

    /**
     * @param list<int>    $weights
     * @param list<string> $expected
     */
    #[DataProvider('allocationCases')]
    public function testAllocationNeverLosesOrInventsAMinorUnit(string $amount, array $weights, array $expected): void
    {
        $shares = Money::of($amount, 'EUR')->allocate($weights);

        self::assertSame($expected, array_map(static fn(Money $m): string => $m->toDecimalString(), $shares));
        self::assertTrue(
            Money::sum($shares)->isEqualTo(Money::of($amount, 'EUR')),
            'The shares no longer add up to the amount they came from.',
        );
    }

    /**
     * The invariant that matters, checked over a wide range rather than at a few
     * hand-picked points: however an amount is split, the parts add back up.
     */
    public function testAllocationInvarianceOverManyAmounts(): void
    {
        $weightSets = [[1, 1], [1, 1, 1], [2, 3, 5], [1, 0, 1], [99, 1]];

        for ($cents = -250; $cents <= 250; ++$cents) {
            $amount = Money::ofMinor($cents, 'EUR');

            foreach ($weightSets as $weights) {
                $shares = $amount->allocate($weights);

                self::assertSame(
                    $cents,
                    Money::sum($shares)->minorAmount,
                    sprintf('Splitting %d cents by [%s] lost or gained a cent.', $cents, implode(',', $weights)),
                );
                self::assertCount(count($weights), $shares);
            }
        }
    }

    public function testSummingAnEmptyListIsRefused(): void
    {
        $this->expectException(MoneyException::class);
        Money::sum([]);
    }

    public function testSumOrZeroNamesTheCurrencyItself(): void
    {
        self::assertTrue(Money::sumOrZero([], 'EUR')->isEqualTo(Money::zero('EUR')));
    }

    public function testComparisons(): void
    {
        $cheap = Money::of('1.00', 'EUR');
        $dear = Money::of('2.00', 'EUR');

        self::assertTrue($dear->isGreaterThan($cheap));
        self::assertTrue($cheap->isLessThan($dear));
        self::assertTrue($cheap->isLessThanOrEqualTo($cheap));
        self::assertTrue($cheap->isPositive());
        self::assertTrue($cheap->negated()->isNegative());
        self::assertTrue(Money::zero('EUR')->isZero());
        self::assertSame('1.00', $cheap->negated()->absolute()->toDecimalString());
    }

    public function testOverflowIsReportedRatherThanSilentlyBecomingAFloat(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessage('Integer overflow');

        Money::ofMinor(\PHP_INT_MAX, 'EUR')->plus(Money::ofMinor(1, 'EUR'));
    }

    public function testTheWireShapeCarriesTheAmountAsAString(): void
    {
        $payload = Money::of('1234567.89', 'EUR')->jsonSerialize();

        self::assertSame(
            ['amount' => '1234567.89', 'currency' => 'EUR', 'scale' => 2, 'minorAmount' => 123456789],
            $payload,
        );
        self::assertIsString($payload['amount']);
    }

    public function testStringRepresentation(): void
    {
        self::assertSame('19.99 EUR', (string) Money::of('19.99', 'EUR'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'thousands separator' => ['1,000.00'];
        yield 'two decimal points' => ['1.0.0'];
        yield 'trailing point' => ['1.'];
        yield 'scientific notation' => ['1e3'];
    }

    #[DataProvider('malformedAmounts')]
    public function testMalformedAmountsAreRefused(string $amount): void
    {
        $this->expectException(MoneyException::class);
        Money::of($amount, 'EUR');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCurrencies(): iterable
    {
        yield 'too short' => ['EU'];
        yield 'too long' => ['EURO'];
        yield 'digits' => ['E1R'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedCurrencies')]
    public function testMalformedCurrencyCodesAreRefused(string $code): void
    {
        $this->expectException(MoneyException::class);
        Currency::of($code);
    }

    public function testCurrencyCodesAreNormalised(): void
    {
        self::assertSame('EUR', Currency::of(' eur ')->code);
    }

    public function testACurrencyOutsideTheStandardCanDeclareItsOwnScale(): void
    {
        $bitcoin = Currency::of('XBT', 8);

        self::assertSame(8, $bitcoin->scale);
        self::assertSame('0.00000001', Money::ofMinor(1, $bitcoin)->toDecimalString());
    }

    /** Same code, different scale, is not the same currency — combining them would corrupt both. */
    public function testTheSameCodeAtADifferentScaleIsADifferentCurrency(): void
    {
        $this->expectException(MoneyException::class);

        Money::ofMinor(1, Currency::of('XBT', 8))->plus(Money::ofMinor(1, Currency::of('XBT', 2)));
    }
}
