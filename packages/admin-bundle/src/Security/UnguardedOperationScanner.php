<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Security;

use ApiPlatform\Metadata\HttpOperation;

/**
 * Flags write operations (POST/PUT/PATCH/DELETE) with no `security:` expression.
 *
 * The app's firewall already requires ROLE_USER on every /api route (see
 * `references/erp-and-permissions.md`'s "Roles per operation" section), so an
 * unguarded operation isn't world-open — it's reachable by *any authenticated
 * user*, regardless of role. That's the right default for most GET endpoints,
 * which is why this only flags mutations: forgetting `security:
 * "is_granted('ROLE_ADMIN')"` (or a domain guard) on a DELETE/PATCH is the
 * mistake class this exists to catch, not "should every read be role-gated".
 */
final class UnguardedOperationScanner
{
    private const array MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param bool $requirePermissionOnReads Extends the scan to GET when the
     *                                       authorization module is on. With
     *                                       permissions in play, a read nobody
     *                                       guarded is a real finding rather
     *                                       than a sensible default — every
     *                                       operation has a permission it could
     *                                       have declared.
     */
    public function __construct(
        private readonly bool $requirePermissionOnReads = false,
    ) {}

    /**
     * @param iterable<array{resourceClass: string, operation: HttpOperation}> $operations
     *
     * @return list<UnguardedOperation>
     */
    public function scan(iterable $operations): array
    {
        $findings = [];

        foreach ($operations as $entry) {
            $operation = $entry['operation'];
            $method = strtoupper($operation->getMethod());

            if (!$this->isInScope($method)) {
                continue;
            }

            if ($this->isGuarded($operation)) {
                continue;
            }

            $findings[] = new UnguardedOperation(
                resourceClass: $entry['resourceClass'],
                resourceShortName: $this->shortName($entry['resourceClass']),
                method: $method,
                uriTemplate: $operation->getUriTemplate(),
            );
        }

        return $findings;
    }

    private function isInScope(string $method): bool
    {
        return $this->requirePermissionOnReads || \in_array($method, self::MUTATING_METHODS, true);
    }

    private function isGuarded(HttpOperation $operation): bool
    {
        $security = $operation->getSecurity();

        return $security !== null && trim($security) !== '';
    }

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return false === $pos ? $class : substr($class, $pos + 1);
    }
}
