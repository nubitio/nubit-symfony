<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Authorization;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\ApiPlatform\Authorization\RowScopeApplier;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Loads the entity a workflow transition targets through the same
 * `#[RowScoped]` enforcement API Platform's own operations use.
 *
 * `WorkflowEngine::apply()` already checks the roles and guard a transition
 * declares, but only after an entity has been loaded — it has no way to
 * refuse a row the caller should never have been able to name in the first
 * place. Tenant isolation still comes for free from the Doctrine SQL filter,
 * which applies to this query the same as any other; this adds the one layer
 * that filter does not cover.
 */
final readonly class RowScopedEntityLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RowScopeApplier $rowScope,
        private TokenStorageInterface $tokenStorage,
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

        $this->rowScope->apply($qb, $class, $this->tokenStorage->getToken()?->getUser());

        /** @var object|null $result */
        $result = $qb->getQuery()->getOneOrNullResult();

        return $result;
    }
}
