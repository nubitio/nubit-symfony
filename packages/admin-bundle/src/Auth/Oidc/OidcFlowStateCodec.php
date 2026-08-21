<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

/**
 * Packs OidcFlowState into an HMAC-signed cookie value and back — this bundle
 * has no server-side session store to keep the redirect leg's state/nonce/PKCE
 * verifier in, so it round-trips through the browser instead, the same way
 * JWTManager trusts a signature instead of server state for access tokens.
 *
 * Not encryption — state/nonce/verifier aren't secret from the user holding
 * the cookie, only tamper-evident from everyone else. The signature is what
 * stops an attacker from forging a state/nonce pair without ever visiting
 * /redirect first.
 */
final readonly class OidcFlowStateCodec
{
    private const int TTL_SECONDS = 600;

    public function __construct(
        private string $secret,
    ) {
    }

    public function encode(OidcFlowState $state): string
    {
        $payload = base64_encode(json_encode([
            'provider' => $state->provider,
            'state' => $state->state,
            'nonce' => $state->nonce,
            'codeVerifier' => $state->codeVerifier,
            'issuedAt' => $state->issuedAt,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    public function decode(string $token): ?OidcFlowState
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;

        $expectedSignature = hash_hmac('sha256', $payload, $this->secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $decoded = base64_decode($payload, strict: true);
        if ($decoded === false) {
            return null;
        }

        try {
            /** @var array<string, mixed>|scalar|null $data */
            $data = json_decode($decoded, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        if (!isset($data['provider'], $data['state'], $data['nonce'], $data['codeVerifier'], $data['issuedAt'])
            || !is_string($data['provider']) || !is_string($data['state']) || !is_string($data['nonce'])
            || !is_string($data['codeVerifier']) || !is_int($data['issuedAt'])
        ) {
            return null;
        }

        if (time() > $data['issuedAt'] + self::TTL_SECONDS) {
            return null;
        }

        return new OidcFlowState(
            $data['provider'],
            $data['state'],
            $data['nonce'],
            $data['codeVerifier'],
            $data['issuedAt'],
        );
    }
}
