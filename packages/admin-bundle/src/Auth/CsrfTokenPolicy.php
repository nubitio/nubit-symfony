<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

/**
 * Names and generation for the double-submit CSRF token.
 *
 * `JWTAuthenticator::buildCookieResponse` sets a `CSRF_TOKEN` cookie
 * alongside the HttpOnly auth cookies — readable by JavaScript on purpose,
 * since the frontend must read it and echo it back as the `X-CSRF-Token`
 * header on every mutating request. `CsrfProtectionListener` then checks the
 * two match. A cross-site page can make the browser attach the cookie, but
 * — same-origin policy — it cannot read the cookie's value to also set the
 * header, so it cannot produce a request that passes both checks.
 */
final class CsrfTokenPolicy
{
    public const string COOKIE_NAME = 'CSRF_TOKEN';
    public const string HEADER_NAME = 'X-CSRF-Token';

    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function __construct() {}
}
