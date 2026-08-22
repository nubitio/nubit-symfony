<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

/** What one resource contributes to the permission catalogue. */
final readonly class ResourcePermissions
{
    /**
     * @param class-string          $resourceClass
     * @param string                $prefix      Permission prefix, e.g. `customer-invoice`.
     * @param list<string>          $permissions Full permission names, sorted.
     * @param array<string, string> $limited     Permission name => Money property it is capped by.
     */
    public function __construct(
        public string $resourceClass,
        public string $prefix,
        public array $permissions,
        public array $limited = [],
    ) {}

    public function limitProperty(string $permission): ?string
    {
        return $this->limited[$permission] ?? null;
    }
}
