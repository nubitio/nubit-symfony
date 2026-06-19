<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Sequence;

use Doctrine\Persistence\ManagerRegistry;
use Nubit\SequenceBundle\Attribute\Sequence;

final class SequenceRegistry
{
    /** @var array<string, Sequence>|null */
    private ?array $byEntityClass = null;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly SequenceMetadata $metadata,
    ) {
    }

    public function getByEntityClass(string $entityClass): ?Sequence
    {
        $this->ensureLoaded();

        return $this->byEntityClass[$entityClass] ?? null;
    }

    private function ensureLoaded(): void
    {
        if (null !== $this->byEntityClass) {
            return;
        }

        $byEntityClass = [];

        foreach ($this->managerRegistry->getManagers() as $manager) {
            foreach ($manager->getMetadataFactory()->getAllMetadata() as $entityMetadata) {
                $entityClass = $entityMetadata->getName();
                $sequence = $this->metadata->read($entityClass);
                if (null !== $sequence) {
                    $byEntityClass[$entityClass] = $sequence;
                }
            }
        }

        $this->byEntityClass = $byEntityClass;
    }
}