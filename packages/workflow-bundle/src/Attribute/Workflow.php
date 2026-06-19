<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Attribute;

use Attribute;
use Nubit\WorkflowBundle\Contract\WorkflowGuardInterface;

/**
 * Declares a field-driven state machine on an API Platform resource.
 *
 * Transitions are exposed as {@code POST {collection}/{id}/transition/{name}}
 * and published to the frontend via {@code x-workflow} on the Hydra API doc.
 *
 * Example:
 *
 *   #[Workflow(
 *       field: 'status',
 *       transitions: [
 *           'send_to_kitchen' => [
 *               'from' => ['open'],
 *               'to' => 'preparing',
 *               'label' => 'Enviar a cocina',
 *               'roles' => ['ROLE_WAITER'],
 *           ],
 *           'pay' => [
 *               'from' => ['served', 'open'],
 *               'to' => 'paid',
 *               'label' => 'Cobrar',
 *               'set' => ['paymentMethod' => 'cash'],
 *           ],
 *       ],
 *   )]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Workflow
{
    /**
     * @param array<string, array{
     *     from: list<string>,
     *     to: string,
     *     label?: string,
     *     roles?: list<string>,
     *     guard?: class-string<WorkflowGuardInterface>,
     *     set?: array<string, mixed>,
     * }> $transitions
     */
    public function __construct(
        public string $field = 'status',
        public array $transitions = [],
        /** Override the collection route path (e.g. /api/orders). Inferred from ApiPlatform when omitted. */
        public ?string $routePrefix = null,
    ) {
    }
}