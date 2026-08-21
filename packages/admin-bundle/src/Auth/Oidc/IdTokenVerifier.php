<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

use Firebase\JWT\JWT;
use Throwable;

/**
 * Verifies an ID token per OpenID Connect Core §3.1.3.7: signature (via the
 * provider's JWKS — see JwksKeyProvider), issuer, audience, and nonce. Token
 * expiry/not-before are enforced by JWT::decode() itself.
 */
final readonly class IdTokenVerifier
{
    public function __construct(
        private JwksKeyProviderInterface $jwksKeyProvider,
    ) {
    }

    /**
     * @return array<string, mixed> The verified claims.
     *
     * @throws OidcAuthenticationException
     */
    public function verify(
        string $idToken,
        OidcProviderConfig $provider,
        OidcDiscoveryDocument $discovery,
        string $expectedNonce,
    ): array {
        try {
            $keys = $this->jwksKeyProvider->keysFor($discovery->jwksUri);
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (Throwable $e) {
            throw new OidcAuthenticationException('Invalid ID token: ' . $e->getMessage(), previous: $e);
        }

        if (!isset($claims['iss']) || !is_string($claims['iss']) || $claims['iss'] !== $discovery->issuer) {
            throw new OidcAuthenticationException('ID token issuer does not match the configured provider.');
        }

        if (!$this->audienceMatches($claims, $provider->clientId)) {
            throw new OidcAuthenticationException('ID token audience does not match this client.');
        }

        // OIDC Core §3.1.3.7 steps 4-5: a multi-audience token must name the
        // party it was issued to, and that party must be us — otherwise a
        // token minted for a *different* client at the same IdP, which merely
        // lists us in `aud`, would authenticate here.
        $this->assertAuthorizedParty($claims, $provider->clientId);

        // Anti-replay: this is what stops an attacker who intercepts one
        // valid ID token from replaying it against a different login attempt.
        if (!isset($claims['nonce']) || !is_string($claims['nonce']) || !hash_equals($expectedNonce, $claims['nonce'])) {
            throw new OidcAuthenticationException('ID token nonce does not match this login attempt.');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertAuthorizedParty(array $claims, string $clientId): void
    {
        if (!isset($claims['azp'])) {
            if (isset($claims['aud']) && is_array($claims['aud']) && count($claims['aud']) > 1) {
                throw new OidcAuthenticationException('ID token has multiple audiences but no "azp" claim.');
            }

            return;
        }

        if (!is_string($claims['azp']) || !hash_equals($clientId, $claims['azp'])) {
            throw new OidcAuthenticationException('ID token authorized party does not match this client.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function audienceMatches(array $claims, string $clientId): bool
    {
        if (!isset($claims['aud'])) {
            return false;
        }

        if (is_string($claims['aud'])) {
            return hash_equals($clientId, $claims['aud']);
        }

        if (!is_array($claims['aud'])) {
            return false;
        }

        $audiences = array_values(array_filter($claims['aud'], is_string(...)));
        foreach ($audiences as $audience) {
            if (hash_equals($clientId, $audience)) {
                return true;
            }
        }

        return false;
    }
}
