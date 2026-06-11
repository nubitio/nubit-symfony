<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Hook invoked after a successful login/refresh response is built (cookie
 * mode only). Use it to attach application cookies — e.g. a Mercure
 * subscriber JWT. Tag: `nubit.admin.login_response_decorator`.
 */
interface LoginResponseDecoratorInterface
{
    public function decorate(
        JsonResponse $response,
        UserInterface $user,
        TokenPair $tokenPair,
        Request $request,
    ): void;
}
