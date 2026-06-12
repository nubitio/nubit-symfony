<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Audit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Audit\Entity\AuditLog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET /api/audit-trail/{resource}/{id} — newest-first entries in the exact
 * shape @nubitio/crud's AuditTrailPanel consumes:
 *
 *     [{ id, timestamp, user, action, changes: { field: { before, after } } }]
 *
 * Wire it on the frontend with:
 *
 *     auditTrail: { enabled: true, apiUrl: (id) => `/api/audit-trail/product/${id}` }
 */
final class AuditTrailController
{
    private const int MAX_ENTRIES = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $resource, string $id, Request $request): JsonResponse
    {
        /** @var list<AuditLog> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AuditLog::class, 'a')
            ->where('a.resource = :resource')
            ->andWhere('a.resourceId = :id')
            ->setParameter('resource', $resource)
            ->setParameter('id', $id)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(min(self::MAX_ENTRIES, max(1, $request->query->getInt('limit', self::MAX_ENTRIES))))
            ->getQuery()
            ->getResult();

        return new JsonResponse(array_map(static fn (AuditLog $log) => [
            'id' => $log->getId(),
            'timestamp' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'user' => $log->getUsername(),
            'action' => $log->getAction(),
            'changes' => $log->getChanges() === [] ? new \stdClass() : $log->getChanges(),
        ], $rows));
    }
}
