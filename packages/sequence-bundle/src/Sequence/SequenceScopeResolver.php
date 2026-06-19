<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Sequence;

use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final readonly class SequenceScopeResolver
{
    public function __construct(
        private PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * @param list<string> $scopePaths
     */
    public function resolve(object $entity, array $scopePaths): string
    {
        if ($scopePaths === []) {
            return '_global';
        }

        $parts = [];
        foreach ($scopePaths as $path) {
            $value = $this->readScopePart($entity, $path);
            $parts[] = $path.':'.$value;
        }

        return implode('|', $parts);
    }

    private function readScopePart(object $entity, string $path): string
    {
        $value = $this->propertyAccessor->getValue($entity, $path);

        if (\is_object($value) && method_exists($value, 'getId')) {
            $id = $value->getId();

            return null === $id ? '0' : (string) $id;
        }

        if (null === $value || '' === $value) {
            return '0';
        }

        return (string) $value;
    }
}