<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nubit\SequenceBundle\Entity\SequenceCounter;
use Nubit\SequenceBundle\Exception\SequenceAllocationException;
use Nubit\SequenceBundle\Sequence\SequenceAllocator;
use Nubit\SequenceBundle\Sequence\SequenceMetadata;
use Nubit\SequenceBundle\Sequence\SequenceScopeResolver;
use Symfony\Component\PropertyAccess\PropertyAccess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for "creating a second record for any #[Sequence]
 * entity fails with 500 once its counter row exists": allocateLocked() used
 * to call EntityManager::persist()/flush() from inside a listener that
 * itself runs synchronously during EntityManager::persist() for the entity
 * being sequenced. Any failure there (routinely a lost race on the counter
 * row's unique constraint) closed the EntityManager for the rest of the
 * request. These tests drive the allocator through a scriptable DBAL
 * Connection stub to prove it now allocates using raw SQL only — it must
 * never call EntityManager::persist()/flush()/find().
 */
final class SequenceAllocatorTest extends TestCase
{
    #[Test]
    public function it_allocates_the_first_value_when_no_counter_row_exists(): void
    {
        $connection = new ScriptedConnectionStub(rowExists: false, lockedValue: '1');

        $allocator = $this->allocator($connection);

        self::assertSame(1, $allocator->allocate('_global', 'invoice'));
        self::assertSame(['INSERT INTO nubit_sequence_counter (scope_key, name, next_value) VALUES (?, ?, 1)'], $connection->insertStatements);
        self::assertTrue($connection->committed);
        self::assertFalse($connection->rolledBack);
    }

    #[Test]
    public function it_allocates_the_next_value_when_the_counter_row_already_exists(): void
    {
        $connection = new ScriptedConnectionStub(rowExists: true, lockedValue: '7');

        $allocator = $this->allocator($connection);

        self::assertSame(7, $allocator->allocate('_global', 'invoice'));
        self::assertSame([], $connection->insertStatements, 'must not re-insert a counter row that already exists');
        self::assertSame(['UPDATE nubit_sequence_counter SET next_value = ? WHERE scope_key = ? AND name = ?'], $connection->updateStatements);
        self::assertSame([8, '_global', 'invoice'], $connection->updateParams[0] ?? null);
    }

    #[Test]
    public function it_never_calls_persist_flush_or_find_on_the_entity_manager(): void
    {
        $connection = new ScriptedConnectionStub(rowExists: true, lockedValue: '3');

        $entityManager = $this->entityManagerMock($connection);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('find');

        $allocator = new SequenceAllocator($entityManager, new SequenceScopeResolver(PropertyAccess::createPropertyAccessor()), new SequenceMetadata());

        self::assertSame(3, $allocator->allocate('_global', 'invoice'));
    }

    #[Test]
    public function it_treats_a_lost_insert_race_as_the_row_already_existing(): void
    {
        $connection = new ScriptedConnectionStub(rowExists: false, lockedValue: '4', insertThrowsUniqueViolation: true);

        $allocator = $this->allocator($connection);

        // A concurrent allocator won the race and created the row first —
        // this call must still succeed by reading the value it committed,
        // and it must not abort the locking transaction that follows.
        self::assertSame(4, $allocator->allocate('_global', 'invoice'));
        self::assertTrue($connection->committed);
        self::assertFalse($connection->rolledBack);
    }

    #[Test]
    public function it_retries_on_a_retryable_exception_and_eventually_succeeds(): void
    {
        $connection = new ScriptedConnectionStub(rowExists: true, lockedValue: '9', deadlockOnAttempt: 1);

        $allocator = $this->allocator($connection);

        self::assertSame(9, $allocator->allocate('_global', 'invoice'));
        self::assertSame(2, $connection->lockingSelectAttempts);
    }

    #[Test]
    public function it_gives_up_after_three_retryable_failures(): void
    {
        $connection = new ScriptedConnectionStub(rowExists: true, lockedValue: '9', deadlockOnAttempt: -1);

        $allocator = $this->allocator($connection);

        $this->expectException(SequenceAllocationException::class);
        $this->expectExceptionMessage('Could not allocate sequence "invoice" for scope "_global" after retries.');

        $allocator->allocate('_global', 'invoice');
    }

    #[Test]
    public function it_wraps_a_non_retryable_failure_and_rolls_back(): void
    {
        $connection = new ScriptedConnectionStub(rowExists: true, lockedValue: '1', failLockingSelectWith: new \RuntimeException('connection lost'));

        $allocator = $this->allocator($connection);

        $this->expectException(SequenceAllocationException::class);
        $this->expectExceptionMessage('Could not allocate sequence "invoice" for scope "_global".');

        try {
            $allocator->allocate('_global', 'invoice');
        } finally {
            self::assertTrue($connection->rolledBack);
            self::assertFalse($connection->committed);
        }
    }

    private function allocator(Connection $connection): SequenceAllocator
    {
        return new SequenceAllocator($this->entityManagerMock($connection), new SequenceScopeResolver(PropertyAccess::createPropertyAccessor()), new SequenceMetadata());
    }

    private function entityManagerMock(Connection $connection): EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $classMetadata = $this->createStub(ClassMetadata::class);
        $classMetadata->method('getTableName')->willReturn('nubit_sequence_counter');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->with(SequenceCounter::class)->willReturn($classMetadata);

        return $entityManager;
    }
}

/**
 * Scriptable Doctrine DBAL Connection stand-in. Deliberately does not call
 * the parent constructor: SequenceAllocator only ever calls fetchOne(),
 * executeStatement(), beginTransaction(), commit() and rollBack() on it, all
 * of which are overridden here without touching a real driver.
 */
final class ScriptedConnectionStub extends Connection
{
    /** @var list<string> */
    public array $insertStatements = [];

    /** @var list<string> */
    public array $updateStatements = [];

    /** @var list<list<mixed>> */
    public array $updateParams = [];

    public int $lockingSelectAttempts = 0;

    public bool $committed = false;

    public bool $rolledBack = false;

    public function __construct(
        private readonly bool $rowExists,
        private readonly string $lockedValue,
        private readonly bool $insertThrowsUniqueViolation = false,
        private readonly int $deadlockOnAttempt = 0,
        private readonly ?\Throwable $failLockingSelectWith = null,
    ) {
    }

    public function fetchOne(string $sql, array $params = [], array $types = []): mixed
    {
        if (str_starts_with($sql, 'SELECT 1 FROM')) {
            return $this->rowExists ? '1' : false;
        }

        // The locking "SELECT next_value ... FOR UPDATE" read.
        ++$this->lockingSelectAttempts;

        if (null !== $this->failLockingSelectWith) {
            throw $this->failLockingSelectWith;
        }

        if ($this->lockingSelectAttempts === $this->deadlockOnAttempt
            || $this->deadlockOnAttempt < 0
        ) {
            throw new class('deadlock detected') extends \RuntimeException implements RetryableException {
            };
        }

        return $this->lockedValue;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (str_starts_with($sql, 'INSERT INTO')) {
            if ($this->insertThrowsUniqueViolation) {
                throw new class('duplicate key value violates unique constraint') extends UniqueConstraintViolationException {
                    public function __construct(string $message)
                    {
                        // Bypass DriverException::__construct(), which requires
                        // a real Driver\Exception to chain — this stub only
                        // needs something SequenceAllocator's
                        // catch (UniqueConstraintViolationException) can match.
                        \Exception::__construct($message);
                    }
                };
            }

            $this->insertStatements[] = $sql;

            return 1;
        }

        if (str_starts_with($sql, 'UPDATE')) {
            $this->updateStatements[] = $sql;
            $this->updateParams[] = $params;

            return 1;
        }

        return 0;
    }

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
        $this->committed = true;
    }

    public function rollBack(): void
    {
        $this->rolledBack = true;
    }
}
