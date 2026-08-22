<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Types;

/**
 * Writes every timestamp in UTC and reads every timestamp back as UTC.
 *
 * A `datetime` column carries no zone, so what lands in it is whatever
 * wall-clock reading the PHP object happened to have. Two servers with
 * different locales then write different values for the same instant, and
 * nothing in the data says which is which. Converting at the boundary means the
 * column has exactly one meaning, and the display zone becomes a presentation
 * decision — see {@see \Nubit\Platform\Time\TimeZoneResolver}.
 *
 * Reading is the half that is easy to forget: Doctrine's own type parses the
 * stored string in PHP's default timezone, so a server set to anything but UTC
 * silently shifts every value it loads.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    private static ?\DateTimeZone $utc = null;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = \DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc());
        }

        /** @var string|null $converted */
        $converted = parent::convertToDatabaseValue($value, $platform);

        return $converted;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, Types::DATETIME_IMMUTABLE, ['null', 'string']);
        }

        $format = $platform->getDateTimeFormatString();
        $converted = \DateTimeImmutable::createFromFormat($format, $value, self::utc());

        if (false === $converted) {
            // Postgres omits fractional seconds when they are zero, so the
            // canonical format does not always match what comes back.
            try {
                $converted = new \DateTimeImmutable($value, self::utc());
            } catch (\Exception $exception) {
                throw InvalidFormat::new($value, Types::DATETIME_IMMUTABLE, $format, $exception);
            }
        }

        return $converted->setTimezone(self::utc());
    }

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
