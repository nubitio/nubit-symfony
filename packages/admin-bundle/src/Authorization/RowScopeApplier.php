<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

use Doctrine\ORM\QueryBuilder;
use Nubit\ApiPlatform\Attribute\RowScoped;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Applies `#[RowScoped]` to a query for a given user.
 *
 * Extracted from the API Platform extension because a queued export runs in a
 * worker, with no session and no `Security` to ask. That is exactly the
 * situation where scope is most likely to be quietly dropped — and an export
 * that ignores row scope hands a warehouse supervisor the whole company in a
 * spreadsheet, asynchronously, with nobody watching.
 *
 * One implementation, two callers: the request path and the worker cannot
 * disagree about what a user may see.
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

    /**
     * Reads the user's claim.
     *
     * @return list<mixed>|null null when the user has no such claim at all
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
