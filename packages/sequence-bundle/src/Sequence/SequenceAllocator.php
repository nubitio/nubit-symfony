<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Sequence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\SequenceBundle\Attribute\Sequence;
use Nubit\SequenceBundle\Entity\SequenceCounter;
use Nubit\SequenceBundle\Exception\SequenceAllocationException;

final readonly class SequenceAllocator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SequenceScopeResolver $scopeResolver,
        private SequenceMetadata $metadata,
    ) {
    }

    public function allocateFormatted(object $entity, Sequence $sequence): string
    {
        $scopeKey = $this->scopeResolver->resolve($entity, $sequence->scope);
        $value = $this->allocate($scopeKey, $sequence->name);

        return $this->metadata->format($sequence, $value);
    }

    public function allocate(string $scopeKey, string $name): int
    {
        $attempts = 0;

        while (true) {
            try {
                return $this->allocateLocked($scopeKey, $name);
            } catch (RetryableException $exception) {
                if (++$attempts >= 3) {
                    throw new SequenceAllocationException(
                        sprintf('Could not allocate sequence "%s" for scope "%s" after retries.', $name, $scopeKey),
                        previous: $exception,
                    );
                }
            }
        }
    }

    /**
     * Allocates the next value for a counter using only raw DBAL statements —
     * deliberately never through EntityManager::persist()/flush().
     *
     * This method is reached from SequenceStampListener::prePersist(), which
     * itself fires synchronously *inside* EntityManager::persist() for the
     * entity being sequenced (Doctrine invokes prePersist listeners before the
     * entity is even scheduled for insertion, let alone flushed). Calling
     * flush() from here used to commit — or fail — that still-in-progress unit
     * of work from within itself. Doctrine unconditionally closes the
     * EntityManager after any exception during flush(), so a failure here
     * (e.g. losing a race on the counter row's unique constraint) permanently
     * broke every later operation on the same EntityManager for the rest of
     * the request; in worker runtimes (FrankenPHP, RoadRunner, Swoole) that
     * reuse the process across requests, it could even poison later, unrelated
     * requests. See nubitio/nubit-symfony issue: "creating a second record for
     * any #[Sequence] entity fails with 500 once its counter row exists."
     */
    private function allocateLocked(string $scopeKey, string $name): int
    {
        $connection = $this->entityManager->getConnection();
        $table = $this->tableName();

        // Runs outside any explicit transaction (autocommit): if this loses a
        // race to a concurrent allocator for the same scope/name, only this
        // one statement is rolled back, not the locking transaction below.
        $this->ensureCounterRowExists($connection, $table, $scopeKey, $name);

        $connection->beginTransaction();

        try {
            $nextValue = $connection->fetchOne(
                sprintf('SELECT next_value FROM %s WHERE scope_key = ? AND name = ? FOR UPDATE', $table),
                [$scopeKey, $name],
            );

            if (false === $nextValue) {
                throw new SequenceAllocationException(
                    sprintf('Sequence counter row for scope "%s" / name "%s" vanished before it could be locked.', $scopeKey, $name),
                );
            }

            $allocated = (int) $nextValue;

            $connection->executeStatement(
                sprintf('UPDATE %s SET next_value = ? WHERE scope_key = ? AND name = ?', $table),
                [$allocated + 1, $scopeKey, $name],
            );

            $connection->commit();

            return $allocated;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            if ($exception instanceof RetryableException) {
                throw $exception;
            }

            throw new SequenceAllocationException(
                sprintf('Could not allocate sequence "%s" for scope "%s".', $name, $scopeKey),
                previous: $exception,
            );
        }
    }

    private function ensureCounterRowExists(Connection $connection, string $table, string $scopeKey, string $name): void
    {
        $exists = $connection->fetchOne(
            sprintf('SELECT 1 FROM %s WHERE scope_key = ? AND name = ?', $table),
            [$scopeKey, $name],
        );

        if (false !== $exists) {
            return;
        }

        try {
            $connection->executeStatement(
                sprintf('INSERT INTO %s (scope_key, name, next_value) VALUES (?, ?, 1)', $table),
                [$scopeKey, $name],
            );
        } catch (UniqueConstraintViolationException) {
            // Lost the race to a concurrent allocator for the same scope/name.
            // The row exists now, which is all this method guarantees.
        }
    }

    private function tableName(): string
    {
        return $this->entityManager->getClassMetadata(SequenceCounter::class)->getTableName();
    }
}
