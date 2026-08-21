<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches (and caches) `{issuer}/.well-known/openid-configuration` — the
 * single document that makes this integration work against any compliant
 * IdP (Okta, Azure AD, Google Workspace, Auth0, Keycloak…) without a
 * provider-specific SDK.
 *
 * Uses Symfony's own CacheInterface (`cache.app` autowires to it by
 * convention in every Symfony app) rather than PSR-16 — PSR-16 needs an
 * explicit adapter wired in the app, Symfony's contract doesn't.
 */
final readonly class OidcDiscoveryClient
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {}

    public function discover(string $issuer): OidcDiscoveryDocument
    {
        $cacheKey = 'nubit_oidc_discovery_' . hash('sha256', $issuer);

        /** @var array<string, mixed> $document */
        $document = $this->cache->get($cacheKey, function (CacheItemInterface $item) use ($issuer): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';

            try {
                return $this->httpClient->request('GET', $url)->toArray();
            } catch (TransportExceptionInterface $e) {
                throw new \RuntimeException(
                    sprintf('Unable to fetch OIDC discovery document from "%s".', $url),
                    previous: $e,
                );
            }
        });

        $discovery = OidcDiscoveryDocument::fromArray($document);

        // The document's own issuer must match what we requested — a
        // mismatch means either misconfiguration or the endpoint is
        // impersonating a different issuer.
        if ($discovery->issuer !== $issuer) {
            $this->cache->delete($cacheKey);
            throw new \RuntimeException(sprintf(
                'OIDC discovery document issuer "%s" does not match configured issuer "%s".',
                $discovery->issuer,
                $issuer,
            ));
        }

        return $discovery;
    }
}
