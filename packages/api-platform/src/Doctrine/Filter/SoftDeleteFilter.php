<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nubit\ApiPlatform\Attribute\SoftDeletable;

/**
 * Doctrine SQL filter that hides soft-deleted rows of every entity marked
 * with #[SoftDeletable]. Registered and enabled automatically by
 * nubitio/admin-bundle; disable per-query with
 * `$em->getFilters()->disable('nubit_soft_delete')`.
 */
class SoftDeleteFilter extends SQLFilter
{
    /** @var array<class-string, string|null> column per entity, null = not soft-deletable */
    private static array $columns = [];

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $class = $targetEntity->getName();

        if (!array_key_exists($class, self::$columns)) {
            $attributes = $targetEntity->getReflectionClass()->getAttributes(SoftDeletable::class);
            self::$columns[$class] = [] === $attributes ? null : $attributes[0]->newInstance()->column;
        }

        $column = self::$columns[$class];
        if (null === $column) {
            return '';
        }

        return sprintf('%s.%s IS NULL', $targetTableAlias, $column);
    }
}
