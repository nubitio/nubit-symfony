<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Applies `#[RowScoped]` to every Doctrine query API Platform builds.
 *
 * Both the collection and the item extension, and the item half is the one that
 * matters: restricting only the list leaves every hidden row one guessed
 * identifier away. That is the same mistake the tenant filter avoids by living
 * in the query rather than in a controller, and this follows it deliberately.
 *
 * The scoping itself lives in {@see RowScopeApplier}, which the queued export
 * also uses — a worker has no session to read, and that is precisely where
 * scope tends to get dropped.
 */
final readonly class RowScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private Security $security,
        private RowScopeApplier $applier,
    ) {}

    /** @param class-string $resourceClass */
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->applier->apply($queryBuilder, $resourceClass, $this->security->getUser());
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
        $this->applier->apply($queryBuilder, $resourceClass, $this->security->getUser());
    }
}
