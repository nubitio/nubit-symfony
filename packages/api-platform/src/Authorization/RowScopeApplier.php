<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Authorization;

use Doctrine\ORM\QueryBuilder;
use Nubit\ApiPlatform\Attribute\RowScoped;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Applies `#[RowScoped]` to a query for a given user.
 *
 * This is the canonical implementation, shared by every package that builds
 * or loads a scoped entity: API Platform's own query extension, the queued
 * export worker (no session, no request — the situation scope is most likely
 * to get quietly dropped), and any custom bundle route that looks an entity
 * up outside API Platform's generated query path. One implementation, every
 * caller — a request path and a worker, or a generated route and a custom
 * one, cannot disagree about what a user may see.
 */
final readonly class RowScopeApplier
{
    public function __construct(
        private RowScopeRegistry $registry,
    ) {}

    /** @param class-string $resourceClass */
    public function apply(QueryBuilder $queryBuilder, string $resourceClass, ?UserInterface $user): void
    {
        $scope = $this->registry->find($resourceClass);

        if (null === $scope || null === $user) {
            return;
        }

        // A scope whose claim the principal cannot answer at all is not the
        // same as one the principal answers with null. The former is a
        // configuration/identity mismatch and must fail closed; treating it
        // like "explicitly unscoped" silently grants every row.
        if (!$this->hasClaim($user, $scope)) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $values = $this->claimValues($user, $scope);

        // Null means the user is explicitly unscoped — a manager, a controller.
        if (null === $values) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0] ?? 'o';

        if ([] === $values) {
            if ($scope->emptyClaimSeesAll) {
                return;
            }

            // Fail closed. An account nobody finished setting up sees nothing,
            // which is visible and fixable; the other reading is a silent grant.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $parameter = 'nubit_row_scope_' . str_replace('-', '_', $scope->field);
        $queryBuilder->andWhere(sprintf('%s.%s IN (:%s)', $alias, $scope->field, $parameter))->setParameter(
            $parameter,
            $values,
        );
    }

    /** True when the principal exposes the configured claim at all. */
    private function hasClaim(UserInterface $user, RowScoped $scope): bool
    {
        $getter = 'get' . ucfirst($scope->claim);

        return (
            method_exists($user, $getter)
            || method_exists($user, $scope->claim)
            || property_exists($user, $scope->claim)
        );
    }

    /**
     * Reads the user's claim.
     *
     * @return list<mixed>|null null when the claim is explicitly null
     */
    private function claimValues(UserInterface $user, RowScoped $scope): ?array
    {
        $getter = 'get' . ucfirst($scope->claim);

        /** @var mixed $claim */
        $claim = match (true) {
            method_exists($user, $getter) => $user->{$getter}(),
            method_exists($user, $scope->claim) => $user->{$scope->claim}(),
            property_exists($user, $scope->claim) => $user->{$scope->claim},
            default => null,
        };

        if (null === $claim) {
            return null;
        }

        if ($claim instanceof \Traversable) {
            $claim = iterator_to_array($claim);
        }

        return is_array($claim) ? array_values($claim) : [$claim];
    }
}
