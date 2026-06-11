<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Attaches the Mercure subscriber JWT cookie to every cookie-mode auth
 * response (login, refresh). Registered when `nubit_admin.mercure.enabled`
 * is true; replace this service to scope topics per user/tenant.
 */
final readonly class MercureCookieDecorator implements LoginResponseDecoratorInterface
{
    public const string MERCURE_COOKIE = 'mercureAuthorization';

    /**
     * @param list<string> $topics
     */
    public function __construct(
        private MercureSubscriberTokenService $tokenService,
        private CookieFactory $cookieFactory,
        private array $topics = ['*'],
        private string $hubPath = '/.well-known/mercure',
    ) {
    }

    public function decorate(
        JsonResponse $response,
        UserInterface $user,
        TokenPair $tokenPair,
        Request $request,
    ): void {
        $response->headers->setCookie($this->cookieFactory->createSecureCookie(
            self::MERCURE_COOKIE,
            $this->tokenService->generateSubscriberToken($this->topics),
            $tokenPair->accessTokenExpiresAt,
            $this->hubPath,
            null,
            Cookie::SAMESITE_LAX,
        ));
    }
}
