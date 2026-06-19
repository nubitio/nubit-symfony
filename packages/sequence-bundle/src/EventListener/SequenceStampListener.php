<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Nubit\SequenceBundle\Sequence\SequenceAllocator;
use Nubit\SequenceBundle\Sequence\SequenceMetadata;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

#[AsDoctrineListener(event: Events::prePersist, priority: -64)]
final class SequenceStampListener
{
    public function __construct(
        private readonly SequenceAllocator $allocator,
        private readonly SequenceMetadata $metadata,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $sequence = $this->metadata->read($entity::class);
        if (null === $sequence) {
            return;
        }

        if (!$this->propertyAccessor->isWritable($entity, $sequence->field)) {
            return;
        }

        $current = $this->propertyAccessor->getValue($entity, $sequence->field);
        if (null !== $current && '' !== $current) {
            return;
        }

        $formatted = $this->allocator->allocateFormatted($entity, $sequence);
        $this->propertyAccessor->setValue($entity, $sequence->field, $formatted);
    }
}