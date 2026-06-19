<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\WorkflowBundle\Exception\WorkflowTransitionException;
use Nubit\WorkflowBundle\Workflow\TransitionDefinition;
use Nubit\WorkflowBundle\Workflow\WorkflowDefinition;
use Nubit\WorkflowBundle\Workflow\WorkflowEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class WorkflowEngineTest extends TestCase
{
    #[Test]
    public function it_applies_transition_and_sets_side_fields(): void
    {
        $entity = new TransitionableEntity();
        $entity->status = 'open';

        $definition = new WorkflowDefinition(
            entityClass: TransitionableEntity::class,
            field: 'status',
            routePrefix: '/api/orders',
            routeKey: 'api_orders',
            transitions: [
                new TransitionDefinition(
                    name: 'pay',
                    from: ['open'],
                    to: 'paid',
                    set: ['paymentMethod' => 'cash'],
                ),
            ],
        );

        $engine = new WorkflowEngine(
            $this->createEntityManagerStub($entity),
            new PropertyAccessor(),
            $this->createMock(AuthorizationCheckerInterface::class),
            $this->createMock(ContainerInterface::class),
            new EventDispatcher(),
        );

        $result = $engine->apply($entity, $definition, 'pay');

        self::assertSame('paid', $result->status);
        self::assertSame('cash', $result->paymentMethod);
    }

    #[Test]
    public function it_rejects_invalid_state(): void
    {
        $entity = new TransitionableEntity();
        $entity->status = 'paid';

        $definition = new WorkflowDefinition(
            entityClass: TransitionableEntity::class,
            field: 'status',
            routePrefix: '/api/orders',
            routeKey: 'api_orders',
            transitions: [
                new TransitionDefinition(name: 'pay', from: ['open'], to: 'paid'),
            ],
        );

        $engine = new WorkflowEngine(
            $this->createEntityManagerStub($entity),
            new PropertyAccessor(),
            $this->createMock(AuthorizationCheckerInterface::class),
            $this->createMock(ContainerInterface::class),
            new EventDispatcher(),
        );

        $this->expectException(WorkflowTransitionException::class);
        $engine->apply($entity, $definition, 'pay');
    }

    private function createEntityManagerStub(object $entity): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with($entity);
        $em->expects(self::once())->method('flush');

        return $em;
    }
}

final class TransitionableEntity
{
    public string $status = 'open';

    public ?string $paymentMethod = null;
}