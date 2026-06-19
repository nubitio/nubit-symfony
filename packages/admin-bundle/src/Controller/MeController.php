<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Controller;

use Nubit\AdminBundle\Session\MeResponseBuilderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Session profile for the React admin. Roles are UX-only; Symfony security
 * expressions remain the real authorization gate.
 */
final readonly class MeController
{
    public function __construct(
        private MeResponseBuilderInterface $meResponseBuilder,
    ) {
    }

    public function __invoke(#[CurrentUser] ?UserInterface $user): JsonResponse
    {
        if (null === $user) {
            return new JsonResponse(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($this->meResponseBuilder->build($user));
    }
}