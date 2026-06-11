<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Firebase\JWT\JWT;

/**
 * Generates Mercure subscriber JWTs signed with the hub secret.
 *
 * Issued as an HttpOnly cookie (see MercureCookieDecorator) so the browser
 * can authenticate SSE connections without exposing the token to JavaScript.
 *
 * @see https://mercure.rocks/docs/hub/subscriptions#authorization
 */
final readonly class MercureSubscriberTokenService
{
    private const string JWT_ALGORITHM = 'HS256';

    public function __construct(
        private string $mercureJwtSecret,
        private int $tokenTtl,
    ) {
    }

    /**
     * @param list<string> $subscribe Topic selectors (URI templates or '*').
     */
    public function generateSubscriberToken(array $subscribe = ['*']): string
    {
        $now = time();

        return JWT::encode(
            [
                'iat' => $now,
                'exp' => $now + $this->tokenTtl,
                'mercure' => ['subscribe' => $subscribe],
            ],
            $this->mercureJwtSecret,
            self::JWT_ALGORITHM,
        );
    }
}
