<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

final readonly class OidcProviderConfig
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $name,
        public string $issuer,
        public string $clientId,
        public string $clientSecret,
        public array $scopes,
        public string $redirectUri,
        public string $postLoginRedirectUri,
    ) {
    }
}
