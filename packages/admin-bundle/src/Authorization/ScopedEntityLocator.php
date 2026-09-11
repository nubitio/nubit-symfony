<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\ApiPlatform\Authorization\RowScopeApplier;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The one place a custom (non-API-Platform) route may load an entity by id.
 *
 * `EntityManager::find()` on its own only inherits tenant isolation — the
 * Doctrine SQL filter every enabled query goes through. Row scope
 * (`#[RowScoped]`) does not: it is applied by {@see RowScopeExtension}, which
 * only hooks into API Platform's own query building, so a bundle controller
 * that calls `find()` directly reaches every row a tenant owns regardless of
 * which of that tenant's rows the caller may see.
 *
 * This runs the same {@see RowScopeApplier} that API Platform and the queued
 * export use, against a query builder rather than a raw `find()`, so a custom
 * route and a generated one apply identical scope. Callers get `null` for a
 * row that exists but is out of scope — the same answer as one that does not
 * exist at all, which is what keeps a guessed id from doubling as an oracle.
 */
final readonly class ScopedEntityLocator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RowScopeApplier $rowScope,
        private Security $security,
    ) {}

    /** @param class-string $class */
    public function find(string $class, mixed $id): ?object
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->andWhere('e.id = :nubit_scoped_entity_id')
            ->setParameter('nubit_scoped_entity_id', $id)
            ->setMaxResults(1);

        $this->rowScope->apply($qb, $class, $this->security->getUser());

        /** @var object|null $result */
        $result = $qb->getQuery()->getOneOrNullResult();

        return $result;
    }
}
