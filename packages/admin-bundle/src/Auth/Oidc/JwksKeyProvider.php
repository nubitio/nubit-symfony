<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches (and caches) a provider's JWKS and turns it into Key objects via
 * firebase/php-jwt's own JWK::parseKeySet — each key carries the algorithm
 * declared in its JWK entry, so JWT::decode() only ever verifies against an
 * algorithm the key itself advertises. There is no "trust the JWT header's
 * alg" step anywhere in this flow (the classic alg-confusion attack surface).
 */
final readonly class JwksKeyProvider implements JwksKeyProviderInterface
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return array<string, Key>
     */
    public function keysFor(string $jwksUri): array
    {
        $cacheKey = 'nubit_oidc_jwks_' . hash('sha256', $jwksUri);

        $jwks = $this->cache->get($cacheKey, function (CacheItemInterface $item) use ($jwksUri): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            try {
                return $this->httpClient->request('GET', $jwksUri)->toArray();
            } catch (TransportExceptionInterface $e) {
                throw new \RuntimeException(sprintf('Unable to fetch JWKS from "%s".', $jwksUri), previous: $e);
            }
        });

        return JWK::parseKeySet($jwks);
    }
}
