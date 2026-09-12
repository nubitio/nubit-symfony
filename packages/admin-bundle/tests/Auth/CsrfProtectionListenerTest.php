<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth;

use Nubit\AdminBundle\Auth\CsrfProtectionListener;
use Nubit\AdminBundle\Auth\CsrfTokenPolicy;
use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\Identity\ApiKeyAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Covers the acceptance criteria in issue #3: a cookie-authenticated
 * mutation without a matching CSRF token is rejected, one with a valid token
 * is accepted, and stateless (Bearer / API key) clients are never subject to
 * the check at all. Also covers issue #16: a browser-supplied `Origin` that
 * disagrees with the request's own host — or an explicitly configured
 * trusted origin — is rejected independently of the token pair.
 */
final class CsrfProtectionListenerTest extends TestCase
{
    public function testRejectsCookieAuthenticatedMutationWithoutCsrfToken(): void
    {
        $event = self::dispatch(self::cookieAuthenticatedRequest('POST'));

        self::assertNotNull($event->getResponse());
        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testRejectsCookieAuthenticatedMutationWithMismatchedCsrfToken(): void
    {
        $request = self::cookieAuthenticatedRequest('POST');
        $request->cookies->set(CsrfTokenPolicy::COOKIE_NAME, 'token-a');
        $request->headers->set(CsrfTokenPolicy::HEADER_NAME, 'token-b');

        $event = self::dispatch($request);

        self::assertNotNull($event->getResponse());
        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testRejectsCookieAuthenticatedMutationWithCsrfCookieButNoHeader(): void
    {
        $request = self::cookieAuthenticatedRequest('POST');
        $request->cookies->set(CsrfTokenPolicy::COOKIE_NAME, 'a-valid-looking-token');

        $event = self::dispatch($request);

        self::assertNotNull($event->getResponse());
        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testAcceptsCookieAuthenticatedMutationWithMatchingCsrfToken(): void
    {
        $request = self::cookieAuthenticatedRequest('POST');
        $token = CsrfTokenPolicy::generate();
        $request->cookies->set(CsrfTokenPolicy::COOKIE_NAME, $token);
        $request->headers->set(CsrfTokenPolicy::HEADER_NAME, $token);

        $event = self::dispatch($request);

        self::assertNull($event->getResponse());
    }

    /**
     * The browser sets `Origin` itself — no script on the page can override
     * it — so a mismatch is rejected even with a token pair that matches
     * exactly. This is the case a shared `cookie_domain` opens up: a script
     * on a sibling subdomain can read the CSRF cookie too, and the token
     * check alone would not catch that.
     */
    public function testRejectsAMatchingCsrfTokenPairFromAMismatchedOrigin(): void
    {
        $request = self::cookieAuthenticatedRequest('POST');
        $token = CsrfTokenPolicy::generate();
        $request->cookies->set(CsrfTokenPolicy::COOKIE_NAME, $token);
        $request->headers->set(CsrfTokenPolicy::HEADER_NAME, $token);
        $request->headers->set('Origin', 'https://evil.example.test');

        $event = self::dispatch($request);

        self::assertNotNull($event->getResponse());
        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testAcceptsAMatchingCsrfTokenPairFromTheRequestsOwnOrigin(): void
    {
        $request = self::cookieAuthenticatedRequest('POST');
        $token = CsrfTokenPolicy::generate();
        $request->cookies->set(CsrfTokenPolicy::COOKIE_NAME, $token);
        $request->headers->set(CsrfTokenPolicy::HEADER_NAME, $token);
        $request->headers->set('Origin', $request->getSchemeAndHttpHost());

        $event = self::dispatch($request);

        self::assertNull($event->getResponse());
    }

    /** A SPA deliberately served from a different host, explicitly trusted. */
    public function testAcceptsAMatchingCsrfTokenPairFromAConfiguredTrustedOrigin(): void
    {
        $request = self::cookieAuthenticatedRequest('POST');
        $token = CsrfTokenPolicy::generate();
        $request->cookies->set(CsrfTokenPolicy::COOKIE_NAME, $token);
        $request->headers->set(CsrfTokenPolicy::HEADER_NAME, $token);
        $request->headers->set('Origin', 'https://app.example.test');

        $event = self::dispatch($request, trustedOrigins: ['https://app.example.test']);

        self::assertNull($event->getResponse());
    }

    /**
     * Not every client sends `Origin` on a same-origin request — its
     * absence carries no signal either way, only its presence does.
     */
    public function testAcceptsAMatchingCsrfTokenPairWithNoOriginHeaderAtAll(): void
    {
        $request = self::cookieAuthenticatedRequest('POST');
        $token = CsrfTokenPolicy::generate();
        $request->cookies->set(CsrfTokenPolicy::COOKIE_NAME, $token);
        $request->headers->set(CsrfTokenPolicy::HEADER_NAME, $token);

        $event = self::dispatch($request);

        self::assertNull($event->getResponse());
    }

    /** @return iterable<string, array{string}> */
    public static function mutatingMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('mutatingMethods')]
    public function testEnforcesTheTokenOnEveryMutatingMethod(string $method): void
    {
        $event = self::dispatch(self::cookieAuthenticatedRequest($method));

        self::assertNotNull($event->getResponse());
    }

    /** @return iterable<string, array{string}> */
    public static function safeMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    #[DataProvider('safeMethods')]
    public function testLeavesSafeMethodsUntouchedEvenWithoutACsrfToken(string $method): void
    {
        $event = self::dispatch(self::cookieAuthenticatedRequest($method));

        self::assertNull($event->getResponse());
    }

    public function testBearerAuthenticatedMutationWorksWithoutACsrfToken(): void
    {
        $request = Request::create('/api/products', 'POST');
        $request->headers->set(JWTAuthenticator::AUTH_HEADER, 'Bearer a.jwt.token');
        // Even a stale auth cookie present alongside the header must not
        // trigger the check — the header is what authenticated this request.
        $request->cookies->set(JWTAuthenticator::AUTH_COOKIE, 'stale-cookie-value');

        $event = self::dispatch($request);

        self::assertNull($event->getResponse());
    }

    public function testApiKeyAuthenticatedMutationWorksWithoutACsrfToken(): void
    {
        $request = Request::create('/api/products', 'POST');
        $request->headers->set(ApiKeyAuthenticator::HEADER, 'nk_live_abc123');

        $event = self::dispatch($request);

        self::assertNull($event->getResponse());
    }

    public function testUnauthenticatedMutationIsLeftToTheFirewall(): void
    {
        // No cookie, no Bearer header, no API key: nothing here is
        // ambient-cookie CSRF exposure, and the firewall answers 401.
        $event = self::dispatch(Request::create('/api/products', 'POST'));

        self::assertNull($event->getResponse());
    }

    public function testLoginRouteIsExemptEvenWithAStaleAuthCookie(): void
    {
        $request = Request::create('/api/auth/login', 'POST');
        $request->attributes->set('_route', JWTAuthenticator::LOGIN_ROUTE);
        $request->cookies->set(JWTAuthenticator::AUTH_COOKIE, 'stale-cookie-value');

        $event = self::dispatch($request);

        self::assertNull($event->getResponse());
    }

    public function testDisabledPolicyNeverRejects(): void
    {
        $event = self::dispatch(self::cookieAuthenticatedRequest('POST'), enabled: false);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequests(): void
    {
        $event = self::dispatch(
            self::cookieAuthenticatedRequest('POST'),
            requestType: HttpKernelInterface::SUB_REQUEST,
        );

        self::assertNull($event->getResponse());
    }

    private static function cookieAuthenticatedRequest(string $method): Request
    {
        $request = Request::create('/api/products', $method);
        $request->cookies->set(JWTAuthenticator::AUTH_COOKIE, 'a-jwt-in-the-cookie');

        return $request;
    }

    /** @param list<string> $trustedOrigins */
    private static function dispatch(
        Request $request,
        bool $enabled = true,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
        array $trustedOrigins = [],
    ): RequestEvent {
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $requestType);

        (new CsrfProtectionListener(new NullLogger(), enabled: $enabled, trustedOrigins: $trustedOrigins))($event);

        return $event;
    }
}
