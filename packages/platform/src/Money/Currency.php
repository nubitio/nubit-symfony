<?php

declare(strict_types=1);

namespace Nubit\Platform\Money;

use Nubit\Platform\Money\Exception\MoneyException;

/**
 * An ISO-4217 currency and, more importantly, how many decimal places it has.
 *
 * The scale is not decoration: it decides what one unit of {@see Money} means.
 * Assuming two decimals everywhere overcharges a Japanese customer by a factor
 * of a hundred and undercharges a Kuwaiti one by ten.
 */
final readonly class Currency implements \Stringable, \JsonSerializable
{
    /**
     * ISO-4217 currencies whose minor unit is not two decimals.
     *
     * Only the exceptions are listed. Every other well-formed code defaults to
     * two, which is correct for the overwhelming majority and keeps this table
     * from becoming a list nobody maintains.
     */
    private const array MINOR_UNIT_EXCEPTIONS = [
        'BHD' => 3,
        'BIF' => 0,
        'CLF' => 4,
        'CLP' => 0,
        'DJF' => 0,
        'GNF' => 0,
        'IQD' => 3,
        'ISK' => 0,
        'JOD' => 3,
        'JPY' => 0,
        'KMF' => 0,
        'KRW' => 0,
        'KWD' => 3,
        'LYD' => 3,
        'OMR' => 3,
        'PYG' => 0,
        'RWF' => 0,
        'TND' => 3,
        'UGX' => 0,
        'UYI' => 0,
        'UYW' => 4,
        'VND' => 0,
        'VUV' => 0,
        'XAF' => 0,
        'XOF' => 0,
        'XPF' => 0,
    ];

    /** Guards against a scale that would overflow integer arithmetic outright. */
    private const int MAX_SCALE = 12;

    private function __construct(
        public string $code,
        public int $scale,
    ) {}

    /**
     * @param int|null $scale Overrides the ISO minor unit — for currencies the
     *                        standard does not cover, such as crypto.
     */
    public static function of(string $code, ?int $scale = null): self
    {
        $normalized = strtoupper(trim($code));

        if (!preg_match('/^[A-Z]{3}$/', $normalized)) {
            throw new MoneyException(sprintf('"%s" is not a three-letter currency code.', $code));
        }

        $resolved = $scale ?? self::MINOR_UNIT_EXCEPTIONS[$normalized] ?? 2;

        if ($resolved < 0 || $resolved > self::MAX_SCALE) {
            throw new MoneyException(sprintf(
                'Currency scale must be between 0 and %d, got %d.',
                self::MAX_SCALE,
                $resolved,
            ));
        }

        return new self($normalized, $resolved);
    }

    public function is(self $other): bool
    {
        return $this->code === $other->code && $this->scale === $other->scale;
    }

    /** The factor between one major unit and one minor unit — 100 for EUR, 1 for JPY. */
    public function subunits(): int
    {
        return 10 ** $this->scale;
    }

    public function __toString(): string
    {
        return $this->code;
    }

    /** @return array{code: string, scale: int} */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'scale' => $this->scale];
    }
}
