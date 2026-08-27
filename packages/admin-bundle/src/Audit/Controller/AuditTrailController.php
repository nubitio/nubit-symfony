<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Audit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Audit\Entity\AuditLog;
use Nubit\AdminBundle\Authorization\PermissionResolver;
use Nubit\AdminBundle\Security\PrivilegedAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
        private readonly PrivilegedAccess $access,
        private readonly ?PermissionResolver $permissions = null,
    ) {}

    public function __invoke(string $resource, string $id, Request $request): JsonResponse
    {
        $this->assertCanRead($resource);

        /** @var list<AuditLog> $rows */
        $rows = $this->entityManager
            ->createQueryBuilder()
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

        return new JsonResponse(array_map(static fn(AuditLog $log) => [
            'id' => $log->getId(),
            'timestamp' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'user' => $log->getUsername(),
            'action' => $log->getAction(),
            'changes' => $log->getChanges() === [] ? new \stdClass() : $log->getChanges(),
        ], $rows));
    }

    /**
     * When the authorization module is on, the trail of a resource is a read
     * of that resource. Without it the firewall's ROLE_USER remains the gate.
     */
    private function assertCanRead(string $resource): void
    {
        if (null === $this->permissions) {
            return;
        }

        if ($this->access->isAdmin()) {
            return;
        }

        $permission = strtolower($resource) . '.read';
        if (!$this->permissions->hasPermission($this->access->user(), $permission)) {
            throw new AccessDeniedHttpException();
        }
    }
}
