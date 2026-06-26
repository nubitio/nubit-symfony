<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Audit;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Nubit\AdminBundle\Audit\Entity\AuditLog;
use Nubit\ApiPlatform\Attribute\AuditMasked;
use Nubit\ApiPlatform\Attribute\Auditable;
use ReflectionClass;
use Stringable;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Captures create/update/delete diffs of #[Auditable] entities.
 *
 * Two-phase on purpose: change sets are read in onFlush (the only point where
 * Doctrine exposes them), but generated ids only exist after the flush — so
 * pending records are buffered and written in postFlush with a second flush.
 * That second flush schedules nothing auditable (AuditLog itself carries no
 * attribute), so it cannot recurse.
 */
class AuditTrailListener
{
    /** @var list<array{entity: object, resource: string, action: 'create'|'update'|'delete', changes: array<string, array{before: mixed, after: mixed}>, id?: ?string}> */
    private array $pending = [];

    /** @var array<class-string, ?string> */
    private array $resourceCache = [];

    /** @var array<class-string, array<string, bool>> field → is masked */
    private array $maskedCache = [];

    /**
     * @param list<string> $ignoredFields
     */
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly array $ignoredFields = ['createdAt', 'updatedAt', 'password'],
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $resource = $this->resolveResource($entity);
            if ($resource === null) {
                continue;
            }

            $changes = [];
            foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
                // Collection-valued entries are PersistentCollections, not
                // [before, after] pairs — relation contents are not audited.
                if (!\is_array($change) || $this->isIgnored($field, $entity)) {
                    continue;
                }
                $changes[$field] = ['before' => null, 'after' => $this->normalizeValue($change[1], $em)];
            }

            $this->pending[] = ['entity' => $entity, 'resource' => $resource, 'action' => 'create', 'changes' => $changes];
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $resource = $this->resolveResource($entity);
            if ($resource === null) {
                continue;
            }

            $changes = [];
            foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
                if (!\is_array($change) || $this->isIgnored($field, $entity)) {
                    continue;
                }
                $changes[$field] = [
                    'before' => $this->normalizeValue($change[0], $em),
                    'after' => $this->normalizeValue($change[1], $em),
                ];
            }

            if ($changes === []) {
                continue;
            }

            $this->pending[] = ['entity' => $entity, 'resource' => $resource, 'action' => 'update', 'changes' => $changes];
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $resource = $this->resolveResource($entity);
            if ($resource === null) {
                continue;
            }

            $changes = [];
            foreach ($uow->getOriginalEntityData($entity) as $field => $before) {
                if ($this->isIgnored($field, $entity)) {
                    continue;
                }
                $changes[$field] = ['before' => $this->normalizeValue($before, $em), 'after' => null];
            }

            // The id vanishes with the row — capture it now.
            $this->pending[] = [
                'entity' => $entity,
                'resource' => $resource,
                'action' => 'delete',
                'changes' => $changes,
                'id' => $this->entityId($entity, $em),
            ];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        $em = $args->getObjectManager();
        $pending = $this->pending;
        $this->pending = [];

        $username = $this->tokenStorage->getToken()?->getUser()?->getUserIdentifier() ?? 'system';

        foreach ($pending as $record) {
            $id = $record['id'] ?? $this->entityId($record['entity'], $em);

            $em->persist(new AuditLog(
                $record['resource'],
                $id ?? '?',
                $record['action'],
                $record['changes'],
                $username,
            ));
        }

        $em->flush();
    }

    private function resolveResource(object $entity): ?string
    {
        $class = $entity::class;

        if (!\array_key_exists($class, $this->resourceCache)) {
            $attributes = new ReflectionClass($class)->getAttributes(Auditable::class);
            if ($attributes === []) {
                $this->resourceCache[$class] = null;
            } else {
                $attribute = $attributes[0]->newInstance();
                $short = strtolower(new ReflectionClass($class)->getShortName());
                $this->resourceCache[$class] = $attribute->resource ?? $short;
            }
        }

        return $this->resourceCache[$class];
    }

    private function isIgnored(string $field, ?object $entity = null): bool
    {
        if (\in_array($field, $this->ignoredFields, true)) {
            return true;
        }

        if ($entity !== null && $this->isMasked($entity::class, $field)) {
            return true;
        }

        return false;
    }

    /** @param class-string $class */
    private function isMasked(string $class, string $field): bool
    {
        if (!isset($this->maskedCache[$class])) {
            $this->maskedCache[$class] = [];
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                if ($property->getAttributes(AuditMasked::class) !== []) {
                    $this->maskedCache[$class][$property->getName()] = true;
                }
            }
        }

        return $this->maskedCache[$class][$field] ?? false;
    }

    private function entityId(object $entity, EntityManagerInterface $em): ?string
    {
        $ids = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);
        if ($ids === []) {
            return null;
        }

        return implode(':', array_map(fn ($id) => (string) $this->normalizeValue($id, $em), $ids));
    }

    /**
     * JSON-safe representation: scalars pass through, dates become ISO 8601,
     * related entities collapse to their id, everything else to a string.
     */
    private function normalizeValue(mixed $value, EntityManagerInterface $em): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (\is_array($value)) {
            return array_map(fn ($item) => $this->normalizeValue($item, $em), $value);
        }

        if (\is_object($value)) {
            if (!$em->getMetadataFactory()->isTransient($value::class)) {
                return $this->entityId($value, $em);
            }

            if ($value instanceof Stringable) {
                return (string) $value;
            }

            return $value::class;
        }

        return get_debug_type($value);
    }
}
