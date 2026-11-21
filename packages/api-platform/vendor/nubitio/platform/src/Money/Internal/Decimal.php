<?php

declare(strict_types=1);

namespace Nubit\Platform\Money\Internal;

use Nubit\Platform\Money\Exception\MoneyException;

/**
 * A decimal literal taken apart into an integer and a scale: "12.345" becomes
 * 12345 at scale 3.
 *
 * @internal
 *
 * Strings are the only safe way a decimal can enter the system. A float cannot
 * hold 0.1, so accepting one would mean the value is already wrong before any
 * arithmetic happens — which is why nothing here takes a float.
 */
final readonly class Decimal
{
    private function __construct(
        public int $unscaled,
        public int $scale,
    ) {}

    public static function parse(string $value): self
    {
        $trimmed = trim($value);

        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $trimmed, $matches)) {
            throw new MoneyException(sprintf('"%s" is not a decimal number.', $value));
        }

        [, $sign, $integerPart, $fractionPart] = $matches + [3 => ''];

        $digits = $integerPart . $fractionPart;
        $scale = strlen($fractionPart);

        // Leading zeros make an otherwise valid literal look too long; strip
        // them before deciding the value cannot be held.
        $normalized = ltrim($digits, '0');
        if (strlen($normalized) > 18) {
            throw new MoneyException(sprintf('"%s" has more significant digits than an integer holds.', $value));
        }

        $unscaled = (int) ($sign . $digits);

        return new self('-' === $sign ? $unscaled : abs($unscaled), $scale);
    }

    /** Restates the value at a larger scale, exactly. */
    public function scaleUp(int $targetScale): self
    {
        if ($targetScale < $this->scale) {
            throw new MoneyException(sprintf('Cannot scale %d down to %d here.', $this->scale, $targetScale));
        }

        return new self(
            IntegerMath::multiply($this->unscaled, IntegerMath::powerOfTen($targetScale - $this->scale)),
            $targetScale,
        );
    }

    /** Renders an unscaled integer back as a decimal literal at the given scale. */
    public static function format(int $unscaled, int $scale): string
    {
        if (0 === $scale) {
            return (string) $unscaled;
        }

        $negative = $unscaled < 0;
        $digits = str_pad((string) IntegerMath::absolute($unscaled), $scale + 1, '0', \STR_PAD_LEFT);

        $integerPart = substr($digits, 0, -$scale);
        $fractionPart = substr($digits, -$scale);

        return ($negative ? '-' : '') . $integerPart . '.' . $fractionPart;
    }
}
