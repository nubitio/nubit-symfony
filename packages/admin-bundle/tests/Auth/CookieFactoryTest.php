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
        $cookie = $factory->createSecureCookie(
            'token',
            'v',
            time() + 3600,
            '/',
            null,
            Cookie::SAMESITE_LAX,
        );

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
}
