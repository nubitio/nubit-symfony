<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Nubit\ApiPlatform\Attribute\RowScoped;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Applies `#[RowScoped]` to every Doctrine query API Platform builds.
 *
 * Both the collection and the item extension, and the item half is the one that
 * matters: restricting only the list leaves every hidden row one guessed
 * identifier away. That is the same mistake the tenant filter avoids by living
 * in the query rather than in a controller, and this follows it deliberately.
 */
final readonly class RowScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private Security $security,
        private RowScopeRegistry $registry,
    ) {}

    /** @param class-string $resourceClass */
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->apply($queryBuilder, $resourceClass);
    }

    /**
     * @param class-string         $resourceClass
     * @param array<string, mixed> $identifiers
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->apply($queryBuilder, $resourceClass);
    }

    /** @param class-string $resourceClass */
    private function apply(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        $scope = $this->registry->find($resourceClass);
        if (null === $scope) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof UserInterface) {
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
            // which is visible and fixable; the other reading is a silent grant
            // of everything.
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
