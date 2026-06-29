<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\EmbeddedLines\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRegistry;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRowSerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Plain JSON array for SmartCrud formDetail reload — the form expects
 * response.data to be a row list, not a Hydra collection envelope.
 */
final readonly class EmbeddedLinesController
{
    public function __construct(
        private EmbeddedLinesRegistry $registry,
        private ManagerRegistry $managerRegistry,
        private EmbeddedLinesRowSerializer $rowSerializer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $definition = $this->registry->getByRoutePath($request->getPathInfo());
        if ($definition === null) {
            throw new NotFoundHttpException('Unknown embedded lines route.');
        }

        $parentId = $request->query->get($definition->parentQueryParam);
        if ($parentId === null || $parentId === '' || $parentId === '0') {
            return new JsonResponse([]);
        }

        $manager = $this->managerRegistry->getManagerForClass($definition->entityClass);
        if ($manager === null) {
            return new JsonResponse([]);
        }

        /** @var class-string $entityClass */
        $entityClass = $definition->entityClass;
        $repository = $manager->getRepository($entityClass);
        $rows = $repository->findBy(
            [$definition->parentProperty => $parentId],
            ['id' => 'ASC'],
        );

        return new JsonResponse($this->rowSerializer->serializeRows($rows, $definition));
    }
}