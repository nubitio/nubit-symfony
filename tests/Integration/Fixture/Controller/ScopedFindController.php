<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Controller;

use Nubit\AdminBundle\Authorization\ScopedEntityLocator;
use Nubit\Tests\Integration\Fixture\Entity\StockMovement;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stands in for a custom bundle route (`MediaFileController`,
 * `WorkflowTransitionController`, `ResourceLocator`) that loads an entity by
 * id outside API Platform's own query path.
 *
 * The point under test is that going through {@see ScopedEntityLocator}
 * instead of a raw `EntityManager::find()` makes such a route agree with
 * `GET /api/stock_movements/{id}` about which rows a caller may reach — see
 * `PermissionTest::testAScopedUserCannotReachAForeignRowByItsIdentifier()`
 * for the API Platform side of the same guarantee.
 */
final readonly class ScopedFindController
{
    public function __construct(
        private ScopedEntityLocator $locator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $id = (int) $request->query->get('id', '0');
        $movement = $this->locator->find(StockMovement::class, $id);

        return new JsonResponse(['found' => $movement instanceof StockMovement]);
    }
}
