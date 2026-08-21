<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\EventListener\Fixture;

use Doctrine\ORM\Query\FilterCollection;
use Nubit\TenantBundle\Doctrine\Filter\TenantFilter;

final class RecordingFilterCollection extends FilterCollection
{
    public bool $enabled = false;
    public TenantFilter $filter;

    public function __construct() {}

    public function isEnabled(string $name): bool
    {
        return $this->enabled;
    }

    public function enable(string $name): TenantFilter
    {
        $this->enabled = true;

        return $this->filter;
    }

    public function getFilter(string $name): TenantFilter
    {
        return $this->filter;
    }

    public function setFiltersStateDirty(): void {}
}
