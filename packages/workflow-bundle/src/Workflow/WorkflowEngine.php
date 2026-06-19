<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Workflow;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\WorkflowBundle\Contract\WorkflowGuardInterface;
use Nubit\WorkflowBundle\Event\WorkflowTransitionAppliedEvent;
use Nubit\WorkflowBundle\Exception\WorkflowTransitionException;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class WorkflowEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropertyAccessorInterface $propertyAccessor,
        private AuthorizationCheckerInterface $authorizationChecker,
        private ContainerInterface $guardLocator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function apply(object $entity, WorkflowDefinition $definition, string $transitionName): object
    {
        $transition = $definition->findTransition($transitionName);
        if (null === $transition) {
            throw WorkflowTransitionException::notFound($transitionName);
        }

        $current = (string) $this->propertyAccessor->getValue($entity, $definition->field);

        if (!\in_array($current, $transition->from, true)) {
            throw WorkflowTransitionException::invalidState($transitionName, $current, $definition->field);
        }

        foreach ($transition->roles as $role) {
            if (!$this->authorizationChecker->isGranted($role)) {
                throw new AccessDeniedException(sprintf('Role "%s" is required for transition "%s".', $role, $transitionName));
            }
        }

        if (null !== $transition->guard) {
            $guard = $this->instantiateGuard($transition->guard);
            if (!$guard->canTransition($entity, $transitionName)) {
                throw WorkflowTransitionException::forbidden(
                    $guard->getBlockReason() ?? sprintf('Transition "%s" is blocked by a guard.', $transitionName),
                );
            }
        }

        $this->propertyAccessor->setValue($entity, $definition->field, $transition->to);

        foreach ($transition->set as $property => $value) {
            if ($this->propertyAccessor->isWritable($entity, (string) $property)) {
                $this->propertyAccessor->setValue($entity, (string) $property, $value);
            }
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new WorkflowTransitionAppliedEvent(
            $entity,
            $definition,
            $transition,
            $current,
        ));

        return $entity;
    }

    /**
     * @param class-string<WorkflowGuardInterface> $guardClass
     */
    private function instantiateGuard(string $guardClass): WorkflowGuardInterface
    {
        if ($this->guardLocator->has($guardClass)) {
            $guard = $this->guardLocator->get($guardClass);
            if ($guard instanceof WorkflowGuardInterface) {
                return $guard;
            }
        }

        $instance = new $guardClass();
        if (!$instance instanceof WorkflowGuardInterface) {
            throw new \InvalidArgumentException(sprintf('Guard "%s" must implement WorkflowGuardInterface.', $guardClass));
        }

        return $instance;
    }
}