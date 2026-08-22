<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Serializer;

use Nubit\Platform\Money\Currency;
use Nubit\Platform\Money\Exception\MoneyException;
use Nubit\Platform\Money\Money;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Puts {@see Money} on the wire, and takes it off again.
 *
 * The amount travels as a decimal string. Every JavaScript runtime parses a
 * JSON number into an IEEE-754 double, so publishing 1234567.89 as a number
 * would hand the client a value that is already approximate — undoing, at the
 * last possible moment, the exactness the whole money layer exists to keep.
 *
 * `minorAmount` rides along because a client that wants to compute — a grid
 * total, a running balance — should do it in integers rather than by parsing
 * the decimal back.
 */
final class MoneyNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /** @return array{amount: string, currency: string, scale: int, minorAmount: int} */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof Money) {
            throw new \InvalidArgumentException('Expected a Money instance.');
        }

        return $data->jsonSerialize();
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Money;
    }

    /**
     * Accepts the shape it emits, and a bare decimal when the resource has
     * already fixed the currency through `default_currency`.
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): ?Money
    {
        if (null === $data || '' === $data) {
            return null;
        }

        $defaultCurrency = $context['default_currency'] ?? null;

        if (is_string($data) || is_int($data)) {
            if (!is_string($defaultCurrency) || '' === $defaultCurrency) {
                throw NotNormalizableValueException::createForUnexpectedDataType(
                    'A money value given as a bare amount needs a currency; send {"amount": "…", "currency": "…"}.',
                    $data,
                    ['object'],
                    self::path($context),
                );
            }

            return $this->build((string) $data, $defaultCurrency, null, $data, $context);
        }

        if (!is_array($data)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                'A money value must be an object with "amount" and "currency".',
                $data,
                ['object'],
                self::path($context),
            );
        }

        $currency = $data['currency'] ?? $defaultCurrency;
        if (!is_string($currency) || '' === $currency) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                'A money value must name its currency.',
                $data,
                ['object'],
                self::path($context),
            );
        }

        $scale = isset($data['scale']) && is_int($data['scale']) ? $data['scale'] : null;

        // minorAmount wins when present: it is the exact form, and a client that
        // sends both has already agreed with itself about the value.
        if (isset($data['minorAmount']) && (is_int($data['minorAmount']) || is_string($data['minorAmount']))) {
            try {
                return Money::ofMinor((int) $data['minorAmount'], Currency::of($currency, $scale));
            } catch (MoneyException $exception) {
                throw $this->rejected($exception, $data, $context);
            }
        }

        $amount = $data['amount'] ?? null;
        if (!is_string($amount) && !is_int($amount)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                'A money value must carry an "amount" as a decimal string.',
                $data,
                ['object'],
                self::path($context),
            );
        }

        return $this->build((string) $amount, $currency, $scale, $data, $context);
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = [],
    ): bool {
        return Money::class === $type;
    }

    /** @return array<class-string|string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [Money::class => true];
    }

    /** @param array<string, mixed> $context */
    private static function path(array $context): ?string
    {
        $path = $context['deserialization_path'] ?? null;

        return is_string($path) ? $path : null;
    }

    /**
     * @param array<array-key, mixed>|string|int $data
     * @param array<string, mixed>               $context
     */
    private function build(string $amount, string $currency, ?int $scale, array|string|int $data, array $context): Money
    {
        try {
            return Money::of($amount, Currency::of($currency, $scale));
        } catch (MoneyException $exception) {
            throw $this->rejected($exception, $data, $context);
        }
    }

    /**
     * @param array<array-key, mixed>|string|int $data
     * @param array<string, mixed>               $context
     */
    private function rejected(MoneyException $exception, array|string|int $data, array $context): \Throwable
    {
        // A rejected amount is client input, so it becomes a 400 through the
        // serializer rather than a 500 through the domain exception.
        return NotNormalizableValueException::createForUnexpectedDataType(
            $exception->getMessage(),
            $data,
            ['object'],
            self::path($context),
            true,
            0,
            $exception,
        );
    }
}
