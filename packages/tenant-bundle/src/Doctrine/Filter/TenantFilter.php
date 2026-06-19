<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Doctrine\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nubit\TenantBundle\Doctrine\TenantScopedMetadata;

final class TenantFilter extends SQLFilter
{
    public const string NAME = 'nubit_tenant';
    public const string PARAMETER = 'tenant_id';
    public const string TENANT_ENTITY_PARAMETER = 'tenant_entity_class';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $metadata = new TenantScopedMetadata(
            $this->hasParameter(self::TENANT_ENTITY_PARAMETER)
                ? trim($this->getParameter(self::TENANT_ENTITY_PARAMETER), "'")
                : null,
        );

        if ($metadata->isTenantRoot($targetEntity->getName())) {
            return sprintf('%s.id = %s', $targetTableAlias, $this->getParameter(self::PARAMETER));
        }

        $field = $metadata->resolveField($targetEntity->getName());
        if (null === $field) {
            return '';
        }

        return sprintf('%s.%s = %s', $targetTableAlias, $field, $this->getParameter(self::PARAMETER));
    }
}