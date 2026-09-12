<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Symfony\Component\HttpFoundation\Cookie;

final readonly class CookieFactory
{
    public function __construct(
        private bool $cookieSecure = true,
        /**
         * Default `Domain` attribute for every cookie this factory creates,
         * unless a call site overrides it. Unset (the default) makes a
         * host-only cookie — the right choice unless the frontend and API
         * are deliberately split across subdomains of the same site and
         * need to share the auth/CSRF cookies (e.g. `.example.com` so both
         * `app.example.com` and `api.example.com` see them).
         */
        private ?string $cookieDomain = null,
    ) {}

    /** @param ''|'lax'|'none'|'strict' $sameSite */
    public function createSecureCookie(
        string $name,
        string $value,
        int $expiresAt,
        string $path = '/',
        ?string $domain = null,
        string $sameSite = Cookie::SAMESITE_STRICT,
    ): Cookie {
        return Cookie::create(
            $name,
            $value,
            $expiresAt,
            $path,
            $domain ?? $this->cookieDomain,
            $this->cookieSecure,
            true, // httpOnly
            false, // raw
            $sameSite,
        );
    }

    /**
     * A CSRF double-submit token cookie: readable by JavaScript (not
     * HttpOnly) so the frontend can echo its value back as the
     * `X-CSRF-Token` header — same-origin policy keeps a cross-site page
     * from reading it, which is the entire point.
     *
     * @param ''|'lax'|'none'|'strict' $sameSite
     */
    public function createCsrfCookie(
        string $name,
        string $value,
        int $expiresAt,
        string $path = '/',
        ?string $domain = null,
        string $sameSite = Cookie::SAMESITE_STRICT,
    ): Cookie {
        return Cookie::create(
            $name,
            $value,
            $expiresAt,
            $path,
            $domain ?? $this->cookieDomain,
            $this->cookieSecure,
            false, // httpOnly — must be readable by JS
            false, // raw
            $sameSite,
        );
    }

    public function createExpiredCookie(string $name, string $path = '/', ?string $domain = null): Cookie
    {
        return Cookie::create(
            $name,
            '',
            time() - 3600,
            $path,
            $domain ?? $this->cookieDomain,
            $this->cookieSecure,
            true, // httpOnly
            false, // raw
            Cookie::SAMESITE_STRICT,
        );
    }
}
