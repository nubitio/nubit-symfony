<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Firebase\JWT\JWT;

/**
 * Generates Mercure subscriber access tokens signed with the hub secret.
 *
 * Mercure.rocks v1 hubs speak RFC 9068 access tokens carrying an RFC 9396
 * `authorization_details` grant — not the legacy `{"mercure":{"subscribe":[...]}}`
 * claim from Mercure 0.x. Topic selectors are WHATWG URL Pattern strings
 * (`https://host/api/*`, `https://host/api/products/*`), not RFC 6570 URI
 * Templates (`{id}`) — the hub rejects the old `{id}` placeholder syntax and
 * the old `?topic=` query parameter alike (`?match`/`?match_urlpattern` now).
 *
 * Issued as an HttpOnly cookie (see MercureCookieDecorator) so the browser
 * can authenticate SSE connections without exposing the token to JavaScript.
 *
 * @see https://mercure.rocks/docs/hub/subscriptions#authorization
 * @see https://datatracker.ietf.org/doc/html/rfc9068
 */
final readonly class MercureSubscriberTokenService
{
    private const string JWT_ALGORITHM = 'HS256';

    public function __construct(
        private string $mercureJwtSecret,
        private int $tokenTtl,
        /**
         * Must equal the hub's trusted issuer (the `issuer` directive in its
         * Caddyfile / MERCURE_TRUSTED_ISSUERS), or the hub rejects every
         * token as coming from an untrusted issuer. Defaults to the hub's
         * own Caddyfile default so apps that don't override
         * MERCURE_TRUSTED_ISSUERS keep working unchanged.
         */
        private string $issuer = 'https://localhost',
    ) {}

    /**
     * @param list<string> $subscribe Topic selectors as WHATWG URL Pattern
     *                                strings (e.g. `https://host/api/*`), or
     *                                `'*'` for everything.
     */
    public function generateSubscriberToken(string $audience, array $subscribe = ['*']): string
    {
        $now = time();

        $payload = [
            'iss' => $this->issuer,
            'aud' => $audience,
            'client_id' => $this->issuer,
            'iat' => $now,
            'exp' => $now + $this->tokenTtl,
            'jti' => self::randomId(),
            'sub' => self::randomId(),
            'authorization_details' => [
                [
                    'type' => 'https://mercure.rocks/authorization-detail',
                    'actions' => ['subscribe'],
                    'topics' => array_map(static fn(string $topic): array => [
                        'match' => $topic,
                        'match_type' => 'urlpattern',
                    ], $subscribe),
                ],
            ],
        ];

        return JWT::encode($payload, $this->mercureJwtSecret, self::JWT_ALGORITHM, null, ['typ' => 'at+jwt']);
    }

    /** A `jti`/`sub` value unique enough for a short-lived, non-persisted token. */
    private static function randomId(): string
    {
        return 'urn:uuid:' . bin2hex(random_bytes(16));
    }
}
