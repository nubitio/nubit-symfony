<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

final readonly class OidcDiscoveryDocument
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
    ) {}

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        return new self(
            issuer: self::requireString($document, 'issuer'),
            authorizationEndpoint: self::requireString($document, 'authorization_endpoint'),
            tokenEndpoint: self::requireString($document, 'token_endpoint'),
            jwksUri: self::requireString($document, 'jwks_uri'),
        );
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function requireString(array $document, string $key): string
    {
        if (!isset($document[$key]) || !is_string($document[$key]) || $document[$key] === '') {
            throw new \RuntimeException(sprintf('OIDC discovery document is missing or has an invalid "%s".', $key));
        }

        return $document[$key];
    }
}
