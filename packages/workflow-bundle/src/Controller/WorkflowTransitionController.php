<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Controller;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\WorkflowBundle\Exception\WorkflowTransitionException;
use Nubit\WorkflowBundle\Workflow\WorkflowEngine;
use Nubit\WorkflowBundle\Workflow\WorkflowRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
final readonly class WorkflowTransitionController
{
    public function __construct(
        private WorkflowRegistry $registry,
        private WorkflowEngine $engine,
        private EntityManagerInterface $entityManager,
        private ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        private SerializerInterface&NormalizerInterface $serializer,
    ) {
    }

    public function __invoke(Request $request, string $id, string $transition): Response
    {
        $definition = $this->registry->getByRoutePrefix($request->attributes->getString('_workflow_route_prefix'));
        if (null === $definition) {
            throw WorkflowTransitionException::notFound($transition);
        }

        $entity = $this->entityManager->getRepository($definition->entityClass)->find($id);
        if (null === $entity) {
            return new JsonResponse(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $entity = $this->engine->apply($entity, $definition, $transition);

        $payload = $this->serializer->normalize($entity, 'json', [
            'groups' => $this->normalizationGroups($definition->entityClass),
            'resource_class' => $definition->entityClass,
        ]);

        return new JsonResponse($payload, Response::HTTP_OK);
    }

    /** @return list<string> */
    private function normalizationGroups(string $entityClass): array
    {
        foreach ($this->resourceMetadataFactory->create($entityClass) as $operation) {
            $context = $operation->getNormalizationContext();
            if (isset($context['groups']) && \is_array($context['groups'])) {
                return array_values($context['groups']);
            }
        }

        return [];
    }
}