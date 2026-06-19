<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Sequence;

use Doctrine\DBAL\LockMode;
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

        while ($attempts < 3) {
            try {
                return $this->allocateLocked($scopeKey, $name);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                ++$attempts;
                $this->entityManager->clear(SequenceCounter::class);
            }
        }

        throw new SequenceAllocationException(
            sprintf('Could not allocate sequence "%s" for scope "%s" after retries.', $name, $scopeKey),
        );
    }

    private function allocateLocked(string $scopeKey, string $name): int
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $repository = $this->entityManager->getRepository(SequenceCounter::class);
            $counter = $repository->findOneBy([
                'scopeKey' => $scopeKey,
                'name' => $name,
            ]);

            if (null !== $counter) {
                $this->entityManager->lock($counter, LockMode::PESSIMISTIC_WRITE);
            } else {
                $counter = (new SequenceCounter())
                    ->setScopeKey($scopeKey)
                    ->setName($name)
                    ->setNextValue(1);
                $this->entityManager->persist($counter);
                $this->entityManager->flush();
                $this->entityManager->refresh($counter);
                $this->entityManager->lock($counter, LockMode::PESSIMISTIC_WRITE);
            }

            $allocated = $counter->allocate();
            $this->entityManager->flush();
            $connection->commit();

            return $allocated;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            if ($exception instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                throw $exception;
            }

            throw new SequenceAllocationException(
                sprintf('Could not allocate sequence "%s" for scope "%s".', $name, $scopeKey),
                previous: $exception,
            );
        }
    }
}