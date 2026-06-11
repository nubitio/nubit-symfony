<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Symfony\Component\HttpFoundation\Cookie;

final readonly class CookieFactory
{
    public function __construct(
        private bool $cookieSecure = true,
    ) {
    }

    public function createSecureCookie(
        string $name,
        string $value,
        int $expiresAt,
        string $path = '/',
        ?string $domain = null,
        string $sameSite = Cookie::SAMESITE_STRICT
    ): Cookie {
        return Cookie::create(
            $name,
            $value,
            $expiresAt,
            $path,
            $domain,
            $this->cookieSecure,
            true,  // httpOnly
            false, // raw
            $sameSite
        );
    }

    public function createExpiredCookie(
        string $name,
        string $path = '/',
        ?string $domain = null
    ): Cookie {
        return Cookie::create(
            $name,
            '',
            time() - 3600,
            $path,
            $domain,
            $this->cookieSecure,
            true,  // httpOnly
            false, // raw
            Cookie::SAMESITE_STRICT
        );
    }
}
