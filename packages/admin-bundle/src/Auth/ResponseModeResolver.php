<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Exception\JsonException;

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
        try {
            $body = $request->toArray();
            if (array_key_exists('response_mode', $body)) {
                return 'json' === $body['response_mode'];
            }
        } catch (JsonException) {
            $body = [];
        }

        $clientTypeHeader = $request->headers->get(self::CLIENT_TYPE_HEADER);
        $clientType = strtolower(is_string($clientTypeHeader) ? $clientTypeHeader : '');

        return '' !== $clientType && in_array($clientType, self::MOBILE_CLIENT_TYPES, true);
    }
}
