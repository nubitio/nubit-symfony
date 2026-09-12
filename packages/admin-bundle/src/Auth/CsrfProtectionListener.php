<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Nubit\AdminBundle\Identity\ApiKeyAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Enforces a double-submit CSRF token on mutating requests authenticated by
 * the ambient `AUTH_TOKEN`/`REFRESH_TOKEN` cookies.
 *
 * `JWTAuthenticator::supports` treats "has a Bearer header" or "has the auth
 * cookie" as equally sufficient to authenticate — which is correct for
 * *authentication*, but a cookie is sent automatically by the browser on a
 * cross-site request while a Bearer header never is. SameSite=Strict already
 * blocks the simple case, but it is not a complete contract on its own
 * (reverse proxies and some cross-subdomain deployments loosen or bypass
 * SameSite enforcement), so mutating cookie-authenticated requests must also
 * carry a `X-CSRF-Token` header matching the `CSRF_TOKEN` cookie the login/
 * refresh response sets (see {@see CsrfTokenPolicy}). Cross-origin
 * JavaScript cannot read that cookie to produce the matching header, so it
 * cannot forge a request that passes both checks — no server-side session
 * state is needed, which matters because this firewall is `stateless: true`.
 *
 * Bearer-token and `X-Api-Key` clients are exempt: those credentials are
 * never attached by the browser automatically, so they are not vulnerable to
 * CSRF the same way and stay usable with no token coupling.
 *
 * The double-submit token alone assumes a cross-site page cannot read the
 * `CSRF_TOKEN` cookie — true under same-origin policy for the API's own
 * origin, but no longer strictly true once `cookie_domain` widens the
 * cookie to a shared parent domain: a script on any sibling subdomain can
 * then read it too. `Origin` is checked as a second, independent signal for
 * exactly that case: it is set by the browser itself on every mutating
 * request and cannot be forged by page content the way a header a script
 * chooses to send can be.
 */
#[AsEventListener]
final readonly class CsrfProtectionListener
{
    /** @var list<string> */
    private const array MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param list<string> $trustedOrigins Origins besides the request's own
     *                                      that may present the double-submit
     *                                      pair — for a SPA deliberately
     *                                      served from a different host than
     *                                      the API under a shared
     *                                      `cookie_domain`.
     */
    public function __construct(
        private LoggerInterface $logger,
        /**
         * Escape hatch for deployments that enforce CSRF policy some other
         * way (e.g. an edge/reverse-proxy Origin check) and want to avoid
         * double protection. Defaults on: the policy should be enforced
         * unless an application explicitly opts out.
         */
        private bool $enabled = true,
        private array $trustedOrigins = [],
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!in_array($request->getMethod(), self::MUTATING_METHODS, strict: true)) {
            return;
        }

        // The login route is what issues the CSRF cookie in the first
        // place — it cannot require a token that does not exist yet. A
        // stale cookie from a previous session must not block a fresh
        // login either (JWTAuthenticator makes the same call for the JWT
        // cookie itself). Login-CSRF is a distinct concern from ambient-
        // cookie CSRF on already-authenticated mutations and out of scope
        // here.
        if (JWTAuthenticator::LOGIN_ROUTE === $request->attributes->get('_route')) {
            return;
        }

        if ($this->isStatelessClient($request)) {
            return;
        }

        if (!$this->isCookieAuthenticated($request)) {
            return;
        }

        if ($this->hasValidCsrfToken($request) && $this->hasTrustedOrigin($request)) {
            return;
        }

        $this->logger->warning('Rejected cookie-authenticated mutation with a missing or invalid CSRF token', [
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp() ?? 'unknown',
        ]);

        $response = new JsonResponse([
            'type' => '/errors/csrf-token-invalid',
            'title' => 'CSRF token missing or invalid',
            'status' => Response::HTTP_FORBIDDEN,
            'detail' => sprintf(
                'This request must be authenticated with a Bearer token, or carry a %s header matching the %s cookie.',
                CsrfTokenPolicy::HEADER_NAME,
                CsrfTokenPolicy::COOKIE_NAME,
            ),
        ], Response::HTTP_FORBIDDEN);
        $response->headers->set('Content-Type', 'application/problem+json');
        $event->setResponse($response);
    }

    private function isStatelessClient(Request $request): bool
    {
        $authHeader = $request->headers->get(JWTAuthenticator::AUTH_HEADER);
        $isBearer = null !== $authHeader && 1 === preg_match('/^\s*Bearer\s+.+$/i', $authHeader);

        return $isBearer || $request->headers->has(ApiKeyAuthenticator::HEADER);
    }

    private function isCookieAuthenticated(Request $request): bool
    {
        return (
            $request->cookies->has(JWTAuthenticator::AUTH_COOKIE)
            || $request->cookies->has(JWTAuthenticator::REFRESH_COOKIE)
        );
    }

    /**
     * Absence of the header is not a signal either way — not every client
     * sends `Origin` on same-origin requests, and the token check above is
     * what carries the actual guarantee. Its presence is authoritative,
     * though: unlike `X-CSRF-Token`, a browser sets `Origin` itself and no
     * script running on the page can override it.
     */
    private function hasTrustedOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if (null === $origin || '' === $origin) {
            return true;
        }

        return $origin === $request->getSchemeAndHttpHost() || in_array($origin, $this->trustedOrigins, true);
    }

    private function hasValidCsrfToken(Request $request): bool
    {
        $cookieToken = $request->cookies->get(CsrfTokenPolicy::COOKIE_NAME);
        $headerToken = $request->headers->get(CsrfTokenPolicy::HEADER_NAME);

        return (
            is_string($cookieToken)
            && '' !== $cookieToken
            && is_string($headerToken)
            && '' !== $headerToken
            && hash_equals($cookieToken, $headerToken)
        );
    }
}
