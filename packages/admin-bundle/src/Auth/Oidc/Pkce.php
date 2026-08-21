<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

/** RFC 7636 PKCE helpers — S256 only (plain is a needless downgrade). */
final class Pkce
{
    private function __construct() {}

    /** 32 random bytes, base64url-encoded → 43 chars, within the spec's 43-128 range. */
    public static function generateVerifier(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    public static function challengeFor(string $verifier): string
    {
        return self::base64UrlEncode(hash('sha256', $verifier, binary: true));
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
