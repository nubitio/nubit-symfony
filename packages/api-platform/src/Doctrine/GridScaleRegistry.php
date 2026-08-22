<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use Nubit\ApiPlatform\Attribute\GridScale;
use Nubit\Platform\Exception\ServiceException;

/**
 * Caches `#[GridScale]` lookups, and refuses a cursor that cannot work.
 *
 * The refusal is the important half. API Platform builds its `next` link by
 * appending `?field[lt]=<last value>`, which does nothing unless a RangeFilter
 * is registered for that field — and an OrderFilter, or the rows come back in
 * whatever order the table felt like. Without them the parameter is ignored and
 * **every page returns the same rows**, silently: no error, no warning, just a
 * grid that scrolls forever through page one and an export that is a quarter of
 * what it should be.
 *
 * That is precisely the class of bug worth spending a boot-time exception on.
 */
final class GridScaleRegistry
{
    /** @var array<string, GridScale|null> */
    private array $cache = [];

    public function find(string $resourceClass): ?GridScale
    {
        if (array_key_exists($resourceClass, $this->cache)) {
            return $this->cache[$resourceClass];
        }

        if (!class_exists($resourceClass)) {
            return $this->cache[$resourceClass] = null;
        }

        $reflection = new \ReflectionClass($resourceClass);
        $attributes = $reflection->getAttributes(GridScale::class);

        $scale = [] === $attributes ? null : $attributes[0]->newInstance();

        if (null !== $scale && $scale->usesCursor()) {
            self::assertCursorIsUsable($reflection, $scale);
        }

        return $this->cache[$resourceClass] = $scale;
    }

    /** @param \ReflectionClass<object> $reflection */
    private static function assertCursorIsUsable(\ReflectionClass $reflection, GridScale $scale): void
    {
        $field = (string) $scale->cursorField;
        $declared = self::filteredProperties($reflection);

        $missing = [];
        foreach ([RangeFilter::class => 'RangeFilter', OrderFilter::class => 'OrderFilter'] as $class => $label) {
            if (!in_array($field, $declared[$class] ?? [], true)) {
                $missing[] = $label;
            }
        }

        // The next link carries only the range parameter. Without a default
        // order on the resource, the cursor walks rows in whatever sequence the
        // database felt like returning — which is the same silent repetition by
        // another route.
        if (!self::declaresOrderOn($reflection, $field)) {
            $missing[] = sprintf(
                'order: [\'%s\' => \'%s\'] on #[ApiResource]',
                $field,
                strtoupper($scale->cursorDirection),
            );
        }

        if ([] === $missing) {
            return;
        }

        throw new ServiceException(sprintf(
            '%s declares cursor pagination on "%s" but has no %s for it. API Platform builds the next-page '
            . 'link as "?%s[lt]=…", which is ignored without those filters — every page would return the '
            . 'same rows. Add: #[ApiFilter(RangeFilter::class, properties: [\'%s\'])] and '
            . '#[ApiFilter(OrderFilter::class, properties: [\'%s\' => \'%s\'])].',
            $reflection->getShortName(),
            $field,
            implode(' and no ', $missing),
            $field,
            $field,
            $field,
            strtoupper($scale->cursorDirection),
        ));
    }

    /** @param \ReflectionClass<object> $reflection */
    private static function declaresOrderOn(\ReflectionClass $reflection, string $field): bool
    {
        foreach ($reflection->getAttributes(ApiResource::class) as $attribute) {
            /** @var mixed $order */
            $order = $attribute->getArguments()['order'] ?? null;

            if (is_array($order) && (isset($order[$field]) || in_array($field, $order, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Properties each filter class is declared for on this resource.
     *
     * `properties` reaches ApiFilter either as a list of names or as a map of
     * name to strategy, and both forms are ordinary in real code.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return array<string, list<string>>
     */
    private static function filteredProperties(\ReflectionClass $reflection): array
    {
        $declared = [];

        foreach ($reflection->getAttributes(ApiFilter::class) as $attribute) {
            $arguments = $attribute->getArguments();

            $filterClass = $arguments[0] ?? $arguments['filterClass'] ?? null;
            if (!is_string($filterClass)) {
                continue;
            }

            /** @var mixed $properties */
            $properties = $arguments['properties'] ?? [];
            if (!is_array($properties)) {
                continue;
            }

            foreach ($properties as $key => $value) {
                $name = is_string($key) ? $key : $value;
                if (is_string($name)) {
                    $declared[$filterClass][] = $name;
                }
            }
        }

        return $declared;
    }
}
