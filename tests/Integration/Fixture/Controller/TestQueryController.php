<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\Tests\Integration\Fixture\Entity\FixtureEntity;
use Nubit\Tests\Integration\Fixture\Entity\GlobalSetting;
use Nubit\Tests\Integration\Fixture\Entity\LooseNote;
use Nubit\Tests\Integration\Fixture\Entity\Widget;
use Nubit\Tests\Integration\Fixture\Entity\WidgetPart;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads fixture data through the real request pipeline.
 *
 * Isolation must be asserted the way an application actually reads: an HTTP
 * request that goes through tenant resolution, the request listener and the
 * Doctrine filter. Calling the entity manager straight from a test would skip
 * precisely the wiring under test.
 */
final readonly class TestQueryController
{
    /** @var array<string, class-string<FixtureEntity>> */
    private const array ENTITIES = [
        'widget' => Widget::class,
        'widget_part' => WidgetPart::class,
        'loose_note' => LooseNote::class,
        'global_setting' => GlobalSetting::class,
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /** Repository read — the `findAll()` path. */
    public function list(Request $request): JsonResponse
    {
        $class = $this->resolveEntity($request);

        $ids = array_map(
            static fn(FixtureEntity $entity): ?int => $entity->getId(),
            $this->entityManager->getRepository($class)->findBy([], ['id' => 'ASC']),
        );

        return new JsonResponse(['ids' => array_values($ids)]);
    }

    /**
     * Direct read by primary key — the path that bypasses DQL entirely and goes
     * through the entity persister.
     */
    public function find(Request $request): JsonResponse
    {
        $class = $this->resolveEntity($request);
        $id = (int) $request->query->get('id', '0');

        $entity = $this->entityManager->find($class, $id);

        return new JsonResponse([
            'found' => null !== $entity,
            'id' => null !== $entity ? $entity->getId() : null,
        ]);
    }

    /** DQL read — the path an application takes for anything non-trivial. */
    public function dql(Request $request): JsonResponse
    {
        $class = $this->resolveEntity($request);

        /** @var list<array{id: int}> $rows */
        $rows = $this->entityManager
            ->createQuery(sprintf('SELECT e.id AS id FROM %s e ORDER BY e.id ASC', $class))
            ->getArrayResult();

        return new JsonResponse(['ids' => array_column($rows, 'id')]);
    }

    /**
     * Read reached through a JOIN. A filter applied only to the root of the
     * query still leaks every joined row, so this is asserted separately.
     */
    public function join(): JsonResponse
    {
        /** @var list<array{id: int, widgetId: int}> $rows */
        $rows = $this->entityManager
            ->createQuery(sprintf(
                'SELECT p.id AS id, w.id AS widgetId FROM %s p JOIN p.widget w ORDER BY p.id ASC',
                WidgetPart::class,
            ))
            ->getArrayResult();

        return new JsonResponse([
            'ids' => array_column($rows, 'id'),
            'widgetIds' => array_column($rows, 'widgetId'),
        ]);
    }

    /** @return class-string<FixtureEntity> */
    private function resolveEntity(Request $request): string
    {
        $alias = (string) $request->query->get('entity', 'widget');

        return self::ENTITIES[$alias] ?? throw new \InvalidArgumentException(sprintf(
            'Unknown fixture entity "%s".',
            $alias,
        ));
    }
}
