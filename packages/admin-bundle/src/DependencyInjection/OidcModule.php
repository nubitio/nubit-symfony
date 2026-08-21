<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use Nubit\AdminBundle\Auth\Oidc\Controller\OidcRedirectController;
use Nubit\AdminBundle\Auth\Oidc\IdTokenVerifier;
use Nubit\AdminBundle\Auth\Oidc\JwksKeyProvider;
use Nubit\AdminBundle\Auth\Oidc\JwksKeyProviderInterface;
use Nubit\AdminBundle\Auth\Oidc\OidcAuthenticator;
use Nubit\AdminBundle\Auth\Oidc\OidcDiscoveryClient;
use Nubit\AdminBundle\Auth\Oidc\OidcFlowStateCodec;
use Nubit\AdminBundle\Auth\Oidc\OidcProviderRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

final class OidcModule
{
    private function __construct() {}

    /**
     * @param array<string, array{issuer: string, client_id: string, client_secret: string, scopes: list<string>, redirect_uri: string, post_login_redirect_uri: string}> $providers
     */
    public static function load(array $providers, string $authSecret, DefaultsConfigurator $services): void
    {
        $services->set(OidcProviderRegistry::class)->arg('$rawProviders', $providers);
        $services->set(OidcDiscoveryClient::class);
        $services->set(JwksKeyProvider::class);
        $services->alias(JwksKeyProviderInterface::class, JwksKeyProvider::class);
        $services->set(IdTokenVerifier::class);
        $services->set(OidcFlowStateCodec::class)->arg('$secret', $authSecret);

        $services->set(OidcRedirectController::class)->tag('controller.service_arguments');
        $services->set(OidcAuthenticator::class);

        // OidcUserResolverInterface is app-owned (like TokenClaimsProviderInterface) —
        // the app must alias it in its own services.yaml. No default binding here.
    }
}
