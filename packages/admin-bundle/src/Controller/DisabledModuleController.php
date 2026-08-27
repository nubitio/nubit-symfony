<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Stands in for a module controller when that module is off.
 *
 * Routes live in one file so an application does not have to import them
 * per module. Hitting one while the module is disabled used to 500 because
 * the controller was not a service. A missing feature is a 404.
 */
final class DisabledModuleController
{
    public function __invoke(): never
    {
        throw new NotFoundHttpException();
    }

    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): never
    {
        throw new NotFoundHttpException();
    }
}
