<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Controller;

use Nubit\AdminBundle\Runtime\RuntimeConfigProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Application runtime flags and defaults for the React shell. Distinct from
 * {@see MeController} (session/user) — this payload is free-form JSON.
 */
final readonly class RuntimeConfigController
{
    public function __construct(
        private bool $enabled,
        private RuntimeConfigProviderInterface $runtimeConfigProvider,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException('Runtime config is not enabled.');
        }

        return new JsonResponse($this->runtimeConfigProvider->getConfig());
    }
}