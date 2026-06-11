<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Controller;

use Nubit\AdminBundle\Auth\CookieFactory;
use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\Auth\RefreshTokenStoreInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/auth/logout — revokes the presented refresh token (cookie or
 * body) and expires the auth cookies.
 */
final readonly class LogoutController
{
    public function __construct(
        private RefreshTokenStoreInterface $refreshTokenStore,
        private CookieFactory $cookieFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);
        $refreshToken = (is_array($body) ? ($body['refreshToken'] ?? null) : null)
            ?? $request->cookies->get(JWTAuthenticator::REFRESH_COOKIE);

        if (is_string($refreshToken) && '' !== $refreshToken) {
            $this->refreshTokenStore->revokeByHash(hash('sha256', $refreshToken));
        }

        $response = new JsonResponse(['message' => 'Logged out']);
        $response->headers->setCookie($this->cookieFactory->createExpiredCookie(JWTAuthenticator::AUTH_COOKIE));
        $response->headers->setCookie($this->cookieFactory->createExpiredCookie(JWTAuthenticator::REFRESH_COOKIE));

        return $response;
    }
}
