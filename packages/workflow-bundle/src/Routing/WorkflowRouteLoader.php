<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Routing;

use Nubit\WorkflowBundle\Controller\WorkflowTransitionController;
use Nubit\WorkflowBundle\Workflow\WorkflowRegistry;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class WorkflowRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(
        private readonly WorkflowRegistry $registry,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \LogicException('Workflow routes have already been loaded.');
        }

        $collection = new RouteCollection();

        foreach ($this->registry->all() as $definition) {
            $path = rtrim($definition->routePrefix, '/') . '/{id}/transition/{transition}';
            $collection->add(
                'nubit_workflow_' . $definition->routeKey,
                new Route(
                    $path,
                    [
                        '_controller' => WorkflowTransitionController::class,
                        '_workflow_route_prefix' => $definition->routePrefix,
                    ],
                    [
                        'id' => '\d+',
                        'transition' => '[a-z][a-z0-9_]*',
                    ],
                    methods: ['POST'],
                ),
            );
        }

        $this->loaded = true;

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nubit_workflow';
    }
}