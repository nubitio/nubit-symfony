<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth;

use Nubit\AdminBundle\Auth\CookieFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class CookieFactoryTest extends TestCase
{
    // ── createSecureCookie ────────────────────────────────────────────────────

    public function testCreateSecureCookieHasCorrectName(): void
    {
        $factory = new CookieFactory(cookieSecure: true);
        $cookie = $factory->createSecureCookie('access_token', 'tok-abc', time() + 3600);

        self::assertSame('access_token', $cookie->getName());
    }

    public function testCreateSecureCookieHasCorrectValue(): void
    {
        $factory = new CookieFactory(cookieSecure: true);
        $cookie = $factory->createSecureCookie('token', 'jwt-value-xyz', time() + 3600);

        self::assertSame('jwt-value-xyz', $cookie->getValue());
    }

    public function testCreateSecureCookieIsHttpOnly(): void
    {
        $factory = new CookieFactory(cookieSecure: true);
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600);

        self::assertTrue($cookie->isHttpOnly());
    }

    public function testCreateSecureCookieIsSecureWhenConfigured(): void
    {
        $factory = new CookieFactory(cookieSecure: true);
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600);

        self::assertTrue($cookie->isSecure());
    }

    public function testCreateSecureCookieIsNotSecureWhenConfiguredFalse(): void
    {
        $factory = new CookieFactory(cookieSecure: false);
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600);

        self::assertFalse($cookie->isSecure());
    }

    public function testCreateSecureCookieDefaultsSameSiteStrict(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600);

        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
    }

    public function testCreateSecureCookieAcceptsCustomSameSite(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600, '/', null, Cookie::SAMESITE_LAX);

        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    public function testCreateSecureCookieDefaultPathIsRoot(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600);

        self::assertSame('/', $cookie->getPath());
    }

    public function testCreateSecureCookieAcceptsCustomPath(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600, '/api');

        self::assertSame('/api', $cookie->getPath());
    }

    public function testCreateSecureCookieSetsDomain(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600, '/', '.efact.app');

        self::assertSame('.efact.app', $cookie->getDomain());
    }

    public function testCreateSecureCookieFallsBackToTheConfiguredDomain(): void
    {
        $factory = new CookieFactory(cookieDomain: '.example.com');
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600);

        self::assertSame('.example.com', $cookie->getDomain());
    }

    public function testCreateSecureCookieCallSitePrevailsOverTheConfiguredDomain(): void
    {
        $factory = new CookieFactory(cookieDomain: '.example.com');
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600, '/', '.other.test');

        self::assertSame('.other.test', $cookie->getDomain());
    }

    public function testCreateSecureCookieHasNoDomainWhenNoneIsConfigured(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createSecureCookie('token', 'v', time() + 3600);

        self::assertNull($cookie->getDomain());
    }

    // ── createCsrfCookie ──────────────────────────────────────────────────────

    public function testCreateCsrfCookieHasCorrectNameAndValue(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createCsrfCookie('CSRF_TOKEN', 'csrf-value', time() + 3600);

        self::assertSame('CSRF_TOKEN', $cookie->getName());
        self::assertSame('csrf-value', $cookie->getValue());
    }

    public function testCreateCsrfCookieIsNotHttpOnly(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createCsrfCookie('CSRF_TOKEN', 'v', time() + 3600);

        // Unlike the auth cookies, this one must be readable by frontend
        // JavaScript so it can be echoed back as the X-CSRF-Token header.
        self::assertFalse($cookie->isHttpOnly());
    }

    public function testCreateCsrfCookieIsSecureWhenConfigured(): void
    {
        $factory = new CookieFactory(cookieSecure: true);
        $cookie = $factory->createCsrfCookie('CSRF_TOKEN', 'v', time() + 3600);

        self::assertTrue($cookie->isSecure());
    }

    public function testCreateCsrfCookieDefaultsSameSiteStrict(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createCsrfCookie('CSRF_TOKEN', 'v', time() + 3600);

        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
    }

    public function testCreateCsrfCookieFallsBackToTheConfiguredDomain(): void
    {
        $factory = new CookieFactory(cookieDomain: '.example.com');
        $cookie = $factory->createCsrfCookie('CSRF_TOKEN', 'v', time() + 3600);

        self::assertSame('.example.com', $cookie->getDomain());
    }

    // ── createExpiredCookie ───────────────────────────────────────────────────

    public function testCreateExpiredCookieIsInThePast(): void
    {
        $factory = new CookieFactory();
        $before = time();
        $cookie = $factory->createExpiredCookie('token');

        self::assertLessThan($before, $cookie->getExpiresTime());
    }

    public function testCreateExpiredCookieHasEmptyValue(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createExpiredCookie('token');

        self::assertSame('', $cookie->getValue());
    }

    public function testCreateExpiredCookieIsHttpOnly(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createExpiredCookie('token');

        self::assertTrue($cookie->isHttpOnly());
    }

    public function testCreateExpiredCookieIsSameSiteStrict(): void
    {
        $factory = new CookieFactory();
        $cookie = $factory->createExpiredCookie('token');

        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
    }

    /**
     * A cookie expired with a different `Domain` than the one it was set
     * with does not clear it — the browser sees them as unrelated cookies.
     * The configured domain must be the default here too, or a deployment
     * using `cookie_domain` could never log out.
     */
    public function testCreateExpiredCookieFallsBackToTheConfiguredDomain(): void
    {
        $factory = new CookieFactory(cookieDomain: '.example.com');
        $cookie = $factory->createExpiredCookie('token');

        self::assertSame('.example.com', $cookie->getDomain());
    }
}
