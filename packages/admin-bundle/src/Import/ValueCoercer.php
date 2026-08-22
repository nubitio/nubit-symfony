<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import;

use Doctrine\ORM\Mapping\ClassMetadata;
use Nubit\AdminBundle\Import\Exception\ImportException;
use Nubit\Platform\Money\Money;
use Nubit\Platform\Money\RoundingMode;

/**
 * Turns a cell of text into the value a property expects.
 *
 * Everything in a spreadsheet is a string, and every conversion is a place to
 * be wrong quietly. The rule here is to refuse rather than approximate: a cell
 * that does not clearly mean what the column needs produces a row error the
 * user can see and fix, not a zero, an epoch date, or a false.
 */
final readonly class ValueCoercer
{
    public function __construct(
        private string $defaultCurrency = 'EUR',
        private NumberFormat $numberFormat = NumberFormat::Auto,
    ) {}

    public function withNumberFormat(NumberFormat $numberFormat): self
    {
        return new self($this->defaultCurrency, $numberFormat);
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @throws ImportException with a message meant for the person holding the file
     */
    public function coerce(ClassMetadata $metadata, string $field, string $raw, ?\ReflectionNamedType $type): mixed
    {
        $value = trim($raw);

        if ('' === $value) {
            return null;
        }

        $target = $type?->getName();

        if (Money::class === $target) {
            return $this->money($value);
        }

        if (in_array($target, [\DateTimeImmutable::class, \DateTimeInterface::class, \DateTime::class], true)) {
            return $this->date($value, \DateTime::class === $target);
        }

        return match ($metadata->hasField($field) ? $metadata->getTypeOfField($field) : $target) {
            'integer', 'smallint', 'bigint', 'int' => $this->integer($value),
            'float', 'decimal' => $this->decimal($value),
            'boolean', 'bool' => $this->boolean($value),
            default => $value,
        };
    }

    private function money(string $value): Money
    {
        // The amount and the currency may share a cell — "1234.50 EUR" is what
        // an export from another system looks like.
        if (preg_match('/^(?<amount>[^\s]+)\s+(?<currency>[A-Za-z]{3})$/', $value, $matches)) {
            return Money::of($this->normalizeNumber($matches['amount']), $matches['currency'], RoundingMode::HalfUp);
        }

        return Money::of($this->normalizeNumber($value), $this->defaultCurrency, RoundingMode::HalfUp);
    }

    private function integer(string $value): int
    {
        $normalized = $this->normalizeNumber($value);

        if (!preg_match('/^[+-]?\d+$/', $normalized)) {
            throw new ImportException(sprintf('"%s" is not a whole number.', $value));
        }

        return (int) $normalized;
    }

    private function decimal(string $value): string
    {
        $normalized = $this->normalizeNumber($value);

        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $normalized)) {
            throw new ImportException(sprintf('"%s" is not a number.', $value));
        }

        return $normalized;
    }

    private function boolean(string $value): bool
    {
        $lower = strtolower($value);

        return match (true) {
            in_array($lower, ['1', 'true', 'yes', 'y', 'si', 'sí', 'x'], true) => true,
            in_array($lower, ['0', 'false', 'no', 'n', ''], true) => false,
            default => throw new ImportException(sprintf('"%s" is not a yes/no value.', $value)),
        };
    }

    private function date(string $value, bool $mutable): \DateTimeInterface
    {
        // An explicit list of formats, never strtotime: it reads 03/04/2026 as
        // March 4th, which is wrong in most of the world and wrong silently.
        // Day-first is tried before month-first because the ISO form above
        // already covers the unambiguous case.
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));

            // createFromFormat is lenient: "31/02/2026" parses and rolls over
            // into March. The warning list is the only thing that reports it,
            // and a date the user did not write must not enter the database.
            $errors = \DateTimeImmutable::getLastErrors();
            $clean = false === $errors || 0 === $errors['warning_count'] && 0 === $errors['error_count'];

            if (false !== $parsed && $clean) {
                $normalized = str_contains($format, 'H') ? $parsed : $parsed->setTime(0, 0);

                return $mutable ? \DateTime::createFromImmutable($normalized) : $normalized;
            }
        }

        throw new ImportException(sprintf(
            '"%s" is not a date the importer recognises (expected YYYY-MM-DD or DD/MM/YYYY).',
            $value,
        ));
    }

    /**
     * Rewrites the thousands and decimal separators a spreadsheet emits.
     *
     * When both separators appear the answer is certain: the one further right
     * is the decimal point. When only one appears it usually is too — three
     * digits after a lone separator with more than one group means grouping,
     * anything else means decimals. One case stays genuinely ambiguous,
     * "1,234", and in Auto it is refused rather than guessed at: reading it
     * wrong moves the amount by a factor of a thousand, which is the worst kind
     * of error an import can make because nothing downstream looks wrong.
     */
    private function normalizeNumber(string $value): string
    {
        $clean = preg_replace('/[\s\x{00A0}]/u', '', $value) ?? $value;

        $decimalSeparator = match ($this->numberFormat) {
            NumberFormat::DotDecimal => '.',
            NumberFormat::CommaDecimal => ',',
            NumberFormat::Auto => $this->inferDecimalSeparator($clean, $value),
        };

        $groupSeparator = '.' === $decimalSeparator ? ',' : '.';

        return str_replace($decimalSeparator, '.', str_replace($groupSeparator, '', $clean));
    }

    private function inferDecimalSeparator(string $clean, string $original): string
    {
        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if (false !== $lastComma && false !== $lastDot) {
            return $lastComma > $lastDot ? ',' : '.';
        }

        $separator = false !== $lastComma ? ',' : (false !== $lastDot ? '.' : null);
        if (null === $separator) {
            return '.';
        }

        $position = false !== $lastComma ? $lastComma : $lastDot;
        $digitsAfter = strlen($clean) - $position - 1;
        $groupsLookRight = 3 === $digitsAfter && $position > 0 && $position <= 3;

        if (!$groupsLookRight) {
            return $separator;
        }

        throw new ImportException(sprintf(
            '"%s" could mean %s or %s. Set the import number format explicitly (dot or comma) so the file '
            . 'is read the way it was written.',
            $original,
            str_replace($separator, '', $clean),
            str_replace($separator, '.', $clean),
        ));
    }
}
