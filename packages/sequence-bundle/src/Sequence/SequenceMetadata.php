<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Sequence;

use Nubit\SequenceBundle\Attribute\Sequence;
use ReflectionClass;

final class SequenceMetadata
{
    public function read(string $entityClass): ?Sequence
    {
        $reflection = new ReflectionClass($entityClass);
        $attributes = $reflection->getAttributes(Sequence::class);

        return $attributes !== [] ? $attributes[0]->newInstance() : null;
    }

    public function format(Sequence $sequence, int $value): string
    {
        $numeric = $sequence->padding > 0
            ? str_pad((string) $value, $sequence->padding, '0', \STR_PAD_LEFT)
            : (string) $value;

        return $sequence->prefix.$numeric;
    }
}