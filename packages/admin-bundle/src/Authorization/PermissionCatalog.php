<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\ApiPlatform\Attribute\Authorized;
use Nubit\ApiPlatform\Authorization\Permission;

/**
 * Every permission the application has, derived from what it already declares.
 *
 * Nothing here is a list somebody maintains. The operations a resource
 * publishes *are* the permission set: a new `Delete()` on an entity creates
 * `invoice.delete` the moment it is written, and removing the operation removes
 * the permission. A hand-kept list would drift, and it would drift in the
 * dangerous direction — an operation nobody thought to add a permission for
 * stays reachable by everyone.
 *
 * Domain actions no HTTP verb implies — approve, post, void — come from
 * `#[Authorized]`, which is the one thing derivation cannot see.
 */
final class PermissionCatalog
{
    /** @var array<string, ResourcePermissions>|null keyed by permission resource prefix */
    private ?array $catalog = null;

    public function __construct(
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadata,
    ) {}

    /** @return array<string, ResourcePermissions> */
    public function all(): array
    {
        return $this->catalog ??= $this->build();
    }

    /** @return list<string> Every permission name, sorted. */
    public function names(): array
    {
        $names = [];
        foreach ($this->all() as $resource) {
            foreach ($resource->permissions as $permission) {
                $names[] = $permission;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    public function knows(string $permission): bool
    {
        return in_array($permission, $this->names(), true);
    }

    /** @param class-string $resourceClass */
    public function forClass(string $resourceClass): ?ResourcePermissions
    {
        foreach ($this->all() as $resource) {
            if ($resource->resourceClass === $resourceClass) {
                return $resource;
            }
        }

        return null;
    }

    /** @return array<string, ResourcePermissions> */
    private function build(): array
    {
        $catalog = [];

        /** @var class-string $class */
        foreach ($this->resourceNames->create() as $class) {
            $authorized = $this->authorized($class);
            $prefix = self::prefixFor($class, $authorized);

            $permissions = [];
            $limited = [];

            foreach ($this->resourceMetadata->create($class) as $resource) {
                foreach ($resource->getOperations() ?? [] as $operation) {
                    if (!$operation instanceof HttpOperation) {
                        continue;
                    }

                    $permissions[] = Permission::forMethod($prefix, $operation->getMethod())->name();
                }
            }

            foreach ($authorized?->actions ?? [] as $action) {
                $permissions[] = Permission::of($prefix, $action)->name();
            }

            foreach ($authorized?->limited ?? [] as $action => $property) {
                $limited[Permission::of($prefix, $action)->name()] = $property;
            }

            $permissions = array_values(array_unique($permissions));
            sort($permissions);

            $catalog[$prefix] = new ResourcePermissions($class, $prefix, $permissions, $limited);
        }

        return $catalog;
    }

    /** @param class-string $class */
    public static function prefixFor(string $class, ?Authorized $authorized = null): string
    {
        $declared = $authorized?->resource;
        if (null !== $declared) {
            return strtolower($declared);
        }

        $separator = strrpos($class, '\\');
        $short = false === $separator ? $class : substr($class, $separator + 1);

        // CustomerInvoice → customer-invoice, so a permission reads the way the
        // URL does and a person can match one to the other at a glance.
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short));
    }

    /** @param class-string $class */
    private function authorized(string $class): ?Authorized
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(Authorized::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
