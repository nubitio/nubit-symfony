<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use Nubit\ApiPlatform\Attribute\Authorized;
use Nubit\ApiPlatform\Authorization\Permission;

/**
 * Gives every operation the permission check it implies, unless it states one.
 *
 * This is the piece that makes the model deny-by-default instead of
 * aspirational. Without it, a permission catalogue is a set of strings nobody
 * evaluates: the operation stays reachable by any authenticated user and the
 * permissions screen becomes a lie the customer discovers during an audit.
 *
 * An explicit `security:` always wins. Inference fills the gap left by the
 * developer who did not think about authorization; it never overrules the one
 * who did.
 */
final readonly class PermissionSecurityMetadataFactory implements ResourceMetadataCollectionFactoryInterface
{
    /**
     * API Platform publishes internal resources of its own — the RFC-7807 error
     * payload above all. Deriving permissions for those makes the error page
     * itself unauthorized, so a 403 stops being a 403 and becomes an exception
     * thrown while rendering the 403. Framework plumbing is exempt by prefix.
     *
     * @var list<string>
     */
    private const array EXEMPT_NAMESPACES = ['ApiPlatform\\'];

    /** @param list<class-string> $exempt Application resources that stay reachable without a permission. */
    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $decorated,
        private array $exempt = [],
    ) {}

    /** @param class-string $resourceClass */
    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->decorated->create($resourceClass);

        if ($this->isExempt($resourceClass)) {
            return $collection;
        }

        $prefix = PermissionCatalog::prefixFor($resourceClass, $this->authorized($resourceClass));

        foreach ($collection as $index => $resource) {
            $operations = $resource->getOperations();
            if (null === $operations) {
                continue;
            }

            $changed = false;
            /** @var string $name */
            foreach ($operations as $name => $operation) {
                if (!$operation instanceof HttpOperation) {
                    continue;
                }

                $declared = $operation->getSecurity();
                if (null !== $declared && '' !== trim($declared)) {
                    continue;
                }

                // A resource-level `security:` also counts as stated: API Platform
                // has already pushed it onto the operation by this point, so
                // reaching here means nothing was declared anywhere.
                $permission = Permission::forMethod($prefix, $operation->getMethod())->name();
                $operations->add($name, $operation->withSecurity(sprintf("is_granted('%s')", $permission)));
                $changed = true;
            }

            if ($changed) {
                $collection[$index] = $resource->withOperations($operations);
            }
        }

        return $collection;
    }

    private function isExempt(string $resourceClass): bool
    {
        if (in_array($resourceClass, $this->exempt, true)) {
            return true;
        }

        foreach (self::EXEMPT_NAMESPACES as $namespace) {
            if (str_starts_with($resourceClass, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /** @param class-string $resourceClass */
    private function authorized(string $resourceClass): ?Authorized
    {
        if (!class_exists($resourceClass)) {
            return null;
        }

        $attributes = (new \ReflectionClass($resourceClass))->getAttributes(Authorized::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
