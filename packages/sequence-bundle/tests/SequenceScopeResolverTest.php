<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Tests;

use Nubit\SequenceBundle\Sequence\SequenceScopeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccessor;

final class SequenceScopeResolverTest extends TestCase
{
    #[Test]
    public function it_builds_global_scope_when_empty(): void
    {
        $resolver = new SequenceScopeResolver(new PropertyAccessor());

        self::assertSame('_global', $resolver->resolve(new ScopedEntity(), []));
    }

    #[Test]
    public function it_resolves_relation_scope_parts(): void
    {
        $resolver = new SequenceScopeResolver(new PropertyAccessor());
        $entity = new ScopedEntity();
        $entity->restaurant = new RestaurantStub(42);

        self::assertSame('restaurant:42', $resolver->resolve($entity, ['restaurant']));
    }
}

final class ScopedEntity
{
    public ?RestaurantStub $restaurant = null;
}

final class RestaurantStub
{
    public function __construct(private int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}