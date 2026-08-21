<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

final readonly class OidcProviderRegistry
{
    /** @var array<string, OidcProviderConfig> */
    private array $providers;

    /**
     * Takes the raw config array (not pre-built OidcProviderConfig objects):
     * Symfony's compiled-container DI definitions can only hold scalars,
     * arrays, and service references, not arbitrary PHP object arguments —
     * building the value objects here keeps NotificationModule-style
     * `$services->set(...)->arg(...)` wiring valid.
     *
     * @param array<string, array{issuer: string, client_id: string, client_secret: string, scopes: list<string>, redirect_uri: string, post_login_redirect_uri: string}> $rawProviders
     */
    public function __construct(array $rawProviders)
    {
        $indexed = [];
        foreach ($rawProviders as $name => $config) {
            $indexed[$name] = new OidcProviderConfig(
                name: $name,
                issuer: $config['issuer'],
                clientId: $config['client_id'],
                clientSecret: $config['client_secret'],
                scopes: $config['scopes'],
                redirectUri: $config['redirect_uri'],
                postLoginRedirectUri: $config['post_login_redirect_uri'],
            );
        }
        $this->providers = $indexed;
    }

    public function get(string $name): ?OidcProviderConfig
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * @return list<OidcProviderConfig>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
