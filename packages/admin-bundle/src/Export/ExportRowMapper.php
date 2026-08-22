<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\Platform\Money\Money;

/**
 * Turns an entity into a spreadsheet row.
 *
 * Reads scalar columns off the mapping rather than serializing: the serializer
 * would resolve relations, which for half a million rows means half a million
 * extra queries. Money is written as its decimal string so the column stays a
 * number the reader can total, and a date as ISO-8601 so it sorts as text.
 */
final class ExportRowMapper
{
    /** @var array<class-string, array<string, string>> */
    private array $columnCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param class-string $resourceClass
     *
     * @return array<string, string> property => header
     */
    public function columnsFor(string $resourceClass): array
    {
        if (isset($this->columnCache[$resourceClass])) {
            return $this->columnCache[$resourceClass];
        }

        $metadata = $this->entityManager->getClassMetadata($resourceClass);

        $columns = [];
        foreach ($metadata->getFieldNames() as $field) {
            $columns[$field] = $field;
        }

        // A money field lives behind an accessor with the columns kept private
        // under another name, so it never appears in getFieldNames().
        foreach ((new \ReflectionClass($resourceClass))->getMethods() as $method) {
            $type = $method->getReturnType();
            if (!$type instanceof \ReflectionNamedType || Money::class !== $type->getName()) {
                continue;
            }

            if (str_starts_with($method->getName(), 'get')) {
                $property = lcfirst(substr($method->getName(), 3));
                $columns[$property] = $property;
            }
        }

        return $this->columnCache[$resourceClass] = $columns;
    }

    /**
     * @param list<string> $properties
     *
     * @return list<string>
     */
    public function row(object $entity, array $properties): array
    {
        $values = [];

        foreach ($properties as $property) {
            $values[] = self::render(self::read($entity, $property));
        }

        return $values;
    }

    private static function read(object $entity, string $property): mixed
    {
        $getter = 'get' . ucfirst($property);

        if (method_exists($entity, $getter)) {
            return $entity->{$getter}();
        }

        $reflection = new \ReflectionObject($entity);

        return $reflection->hasProperty($property) ? $reflection->getProperty($property)->getValue($entity) : null;
    }

    private static function render(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            $value instanceof Money => $value->toDecimalString(),
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            // A relation or a JSON column. Compact rather than dropped: the data
            // is still visible, and the alternative is a column of blanks.
            default => (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        };
    }
}
