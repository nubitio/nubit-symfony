<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

/** What the redirect leg needs the callback leg to still know, after the browser round-trips to the IdP and back. */
final readonly class OidcFlowState
{
    public function __construct(
        public string $provider,
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public int $issuedAt,
    ) {
    }
}
