<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Tests;

use Nubit\SequenceBundle\Attribute\Sequence;
use Nubit\SequenceBundle\Sequence\SequenceMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SequenceMetadataTest extends TestCase
{
    #[Test]
    public function it_formats_with_prefix_and_padding(): void
    {
        $metadata = new SequenceMetadata();
        $sequence = new Sequence(prefix: 'ORD-', padding: 4);

        self::assertSame('ORD-0007', $metadata->format($sequence, 7));
    }

    #[Test]
    public function it_reads_sequence_attribute(): void
    {
        $metadata = new SequenceMetadata();
        $sequence = $metadata->read(SequencedEntity::class);

        self::assertInstanceOf(Sequence::class, $sequence);
        self::assertSame('code', $sequence->field);
    }

    #[Test]
    public function it_serializes_to_open_api(): void
    {
        $sequence = new Sequence(
            field: 'number',
            name: 'order',
            prefix: 'ORD-',
            padding: 4,
            scope: ['restaurant'],
        );

        self::assertSame(
            [
                'field' => 'number',
                'name' => 'order',
                'prefix' => 'ORD-',
                'padding' => 4,
                'scope' => ['restaurant'],
            ],
            $sequence->toOpenApi(),
        );
    }
}

#[Sequence(field: 'code', name: 'invoice', prefix: 'INV-', padding: 3)]
final class SequencedEntity
{
}