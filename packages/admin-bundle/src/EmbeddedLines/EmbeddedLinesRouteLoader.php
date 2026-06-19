<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\EmbeddedLines;

use Nubit\AdminBundle\EmbeddedLines\Controller\EmbeddedLinesController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class EmbeddedLinesRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(
        private readonly EmbeddedLinesRegistry $registry,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \LogicException('Embedded lines routes have already been loaded.');
        }

        $collection = new RouteCollection();

        foreach ($this->registry->all() as $definition) {
            $collection->add(
                $definition->routeName,
                new Route(
                    $definition->routePath,
                    ['_controller' => EmbeddedLinesController::class],
                    methods: ['GET'],
                ),
            );
        }

        $this->loaded = true;

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nubit_embedded_lines';
    }
}