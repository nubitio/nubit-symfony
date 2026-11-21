<?php

declare(strict_types=1);

namespace Nubit\Platform\Money\Internal;

use Nubit\Platform\Money\Exception\MoneyException;
use Nubit\Platform\Money\RoundingMode;

/**
 * Exact integer arithmetic with explicit overflow and rounding behaviour.
 *
 * @internal
 *
 * PHP promotes an overflowing integer operation to float, which is precisely
 * the silent precision loss the money layer exists to prevent — so every result
 * is checked for having stayed an integer. Nothing here uses bcmath or gmp:
 * requiring an extension would push the decision onto every consumer, and
 * 64-bit integers cover any amount an ERP will hold.
 */
final class IntegerMath
{
    private function __construct() {}

    public static function add(int $a, int $b): int
    {
        $result = $a + $b;

        return is_int($result) ? $result : self::overflow(sprintf('%d + %d', $a, $b));
    }

    public static function subtract(int $a, int $b): int
    {
        $result = $a - $b;

        return is_int($result) ? $result : self::overflow(sprintf('%d - %d', $a, $b));
    }

    public static function multiply(int $a, int $b): int
    {
        $result = $a * $b;

        return is_int($result) ? $result : self::overflow(sprintf('%d * %d', $a, $b));
    }

    public static function negate(int $value): int
    {
        return \PHP_INT_MIN === $value ? self::overflow(sprintf('-(%d)', $value)) : -$value;
    }

    public static function absolute(int $value): int
    {
        return \PHP_INT_MIN === $value ? self::overflow(sprintf('abs(%d)', $value)) : abs($value);
    }

    public static function powerOfTen(int $exponent): int
    {
        if ($exponent < 0) {
            throw new MoneyException(sprintf('Negative power of ten: %d.', $exponent));
        }

        $result = 1;
        for ($i = 0; $i < $exponent; ++$i) {
            $result = self::multiply($result, 10);
        }

        return $result;
    }

    /**
     * Divides and resolves the remainder according to `$mode`.
     *
     * The quotient PHP produces truncates toward zero, so every mode is
     * expressed relative to that: `$sign` is the direction "away from zero"
     * points in, and the remainder decides whether to take a step in it.
     */
    public static function divide(int $dividend, int $divisor, RoundingMode $mode): int
    {
        if (0 === $divisor) {
            throw new MoneyException('Division by zero.');
        }

        $quotient = intdiv($dividend, $divisor);
        $remainder = $dividend % $divisor;

        if (0 === $remainder) {
            return $quotient;
        }

        if (RoundingMode::Unnecessary === $mode) {
            throw new MoneyException(sprintf(
                'Rounding is necessary to divide %d by %d, but RoundingMode::Unnecessary was requested.',
                $dividend,
                $divisor,
            ));
        }

        $negative = $dividend < 0 !== $divisor < 0;
        $step = $negative ? -1 : 1;

        // Twice the remainder against the divisor answers "past, at, or before
        // the halfway point" without leaving integer arithmetic.
        $comparison = (self::absolute($remainder) * 2) <=> self::absolute($divisor);

        $roundAway = match ($mode) {
            RoundingMode::Up => true,
            RoundingMode::Down => false,
            RoundingMode::Ceiling => !$negative,
            RoundingMode::Floor => $negative,
            RoundingMode::HalfUp => $comparison >= 0,
            RoundingMode::HalfDown => $comparison > 0,
            RoundingMode::HalfEven => $comparison > 0 || 0 === $comparison && 0 !== ($quotient % 2),
            RoundingMode::Unnecessary => false,
        };

        return $roundAway ? self::add($quotient, $step) : $quotient;
    }

    private static function overflow(string $operation): never
    {
        throw new MoneyException(sprintf(
            'Integer overflow in money arithmetic (%s). The amount exceeds what 64-bit integers hold.',
            $operation,
        ));
    }
}
