<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * Decides whether auth endpoints respond with tokens in the JSON body
 * (mobile / API clients) or as HttpOnly cookies (web).
 *
 * Resolution order:
 *  1. Explicit `response_mode` field in the JSON body (`cookie` | `json`).
 *  2. `X-Client-Type: android|ios` header → json.
 *  3. Default: cookie.
 */
final readonly class ResponseModeResolver
{
    private const string CLIENT_TYPE_HEADER = 'X-Client-Type';
    private const array MOBILE_CLIENT_TYPES = ['android', 'ios'];

    public function wantsJsonTokens(Request $request): bool
    {
        $body = json_decode($request->getContent(), true);
        if (is_array($body) && isset($body['response_mode'])) {
            return 'json' === $body['response_mode'];
        }

        $clientType = strtolower($request->headers->get(self::CLIENT_TYPE_HEADER, ''));

        return '' !== $clientType && in_array($clientType, self::MOBILE_CLIENT_TYPES, true);
    }
}
