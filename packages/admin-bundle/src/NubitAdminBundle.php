<?php

declare(strict_types=1);

namespace Nubit\AdminBundle;

use Nubit\AdminBundle\Auth\CookieFactory;
use Nubit\AdminBundle\Auth\DefaultTokenClaimsProvider;
use Nubit\AdminBundle\Auth\DoctrineRefreshTokenStore;
use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\Auth\JWTManager;
use Nubit\AdminBundle\Auth\JWTManagerInterface;
use Nubit\AdminBundle\Auth\LoginResponseDecoratorInterface;
use Nubit\AdminBundle\Auth\RefreshTokenStoreInterface;
use Nubit\AdminBundle\Auth\ResponseModeResolver;
use Nubit\AdminBundle\Auth\TokenClaimsProviderInterface;
use Nubit\AdminBundle\Auth\TokenGenerator;
use Nubit\AdminBundle\Controller\LoginController;
use Nubit\AdminBundle\Controller\LogoutController;
use Nubit\AdminBundle\Controller\RefreshController;
use Nubit\AdminBundle\Tenant\AllowAllFeatureChecker;
use Nubit\AdminBundle\Tenant\SingleTenantConnectionSwitcher;
use Nubit\AdminBundle\Tenant\SingleTenantRegistry;
use Nubit\AdminBundle\Tenant\UnlimitedQuotaEnforcer;
use Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter;
use Nubit\ApiPlatform\Doctrine\Filter\GridVirtualFieldInterface;
use Nubit\ApiPlatform\Http\ApiResponseListener;
use Nubit\ApiPlatform\Http\ExceptionListener;
use Nubit\ApiPlatform\OpenApi\TranslatedDocumentationNormalizer;
use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;
use Nubit\Platform\Quota\Contract\QuotaEnforcerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * One-line install for the Nubit admin stack backend:
 *
 *     composer require nubitio/admin-bundle
 *
 * Registers the API Platform bridge (grid filter, translated docs, headers),
 * the dual cookie/Bearer JWT auth (login/refresh/logout routes), and
 * single-tenant defaults for the Nubit\Platform contracts.
 */
final class NubitAdminBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('auth')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('secret')
                            ->info('Secret used to sign JWTs. Defaults to %env(APP_SECRET)%.')
                            ->defaultValue('%env(APP_SECRET)%')
                        ->end()
                        ->integerNode('access_token_ttl')->defaultValue(3600)->end()
                        ->integerNode('refresh_token_ttl')->defaultValue(1209600)->end()
                        ->booleanNode('cookie_secure')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('api')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('translated_docs')
                            ->info('Decorate the Hydra docs normalizer to translate labels and forward x-crud hints.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('docs_locale')
                            ->info('Locale used when translating API docs. Reads APP_API_LOCALE, falling back to "en".')
                            ->defaultValue('%env(default:nubit_admin.api.default_docs_locale:APP_API_LOCALE)%')
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('single_tenant_defaults')
                    ->info('Bind noop single-tenant implementations of the Nubit\\Platform contracts.')
                    ->defaultTrue()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set('nubit_admin.api.default_docs_locale', 'en');

        $services = $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure();

        // ── Extension-point autoconfiguration ────────────────────────────────
        $builder->registerForAutoconfiguration(GridVirtualFieldInterface::class)
            ->addTag('nubit.api_platform.grid_virtual_field');
        $builder->registerForAutoconfiguration(LoginResponseDecoratorInterface::class)
            ->addTag('nubit.admin.login_response_decorator');

        // ── nubitio/api-platform bridge ──────────────────────────────────────
        $services->set(DataGridFilter::class);
        $services->set(ApiResponseListener::class);
        $services->set(ExceptionListener::class);

        if ($config['api']['translated_docs']) {
            $services->set(TranslatedDocumentationNormalizer::class)
                ->decorate('api_platform.hydra.normalizer.documentation')
                ->arg('$inner', service('.inner'))
                ->arg('$apiLocale', $config['api']['docs_locale']);
        }

        // ── Auth ─────────────────────────────────────────────────────────────
        $services->set(JWTManager::class)
            ->arg('$secret', $config['auth']['secret']);
        $services->alias(JWTManagerInterface::class, JWTManager::class);

        $services->set(ResponseModeResolver::class);

        $services->set(CookieFactory::class)
            ->arg('$cookieSecure', $config['auth']['cookie_secure']);

        $services->set(DefaultTokenClaimsProvider::class);
        $services->alias(TokenClaimsProviderInterface::class, DefaultTokenClaimsProvider::class);

        $services->set(DoctrineRefreshTokenStore::class);
        $services->alias(RefreshTokenStoreInterface::class, DoctrineRefreshTokenStore::class);

        $services->set(TokenGenerator::class)
            ->arg('$accessTokenTtl', $config['auth']['access_token_ttl'])
            ->arg('$refreshTokenTtl', $config['auth']['refresh_token_ttl']);

        $services->set(JWTAuthenticator::class);

        $services->set(LoginController::class)->tag('controller.service_arguments');
        $services->set(RefreshController::class)->tag('controller.service_arguments');
        $services->set(LogoutController::class)->tag('controller.service_arguments');

        // ── Tenant context + single-tenant defaults ──────────────────────────
        $services->set(TenantContext::class);

        if ($config['single_tenant_defaults']) {
            $services->set(SingleTenantRegistry::class);
            $services->alias(TenantRegistryInterface::class, SingleTenantRegistry::class);

            $services->set(SingleTenantConnectionSwitcher::class);
            $services->alias(TenantConnectionSwitcherInterface::class, SingleTenantConnectionSwitcher::class);

            $services->set(AllowAllFeatureChecker::class);
            $services->alias(FeatureCheckerInterface::class, AllowAllFeatureChecker::class);

            $services->set(UnlimitedQuotaEnforcer::class);
            $services->alias(QuotaEnforcerInterface::class, UnlimitedQuotaEnforcer::class);
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        // Map the bundle's RefreshToken entity.
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'NubitAdminBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => __DIR__ . '/Entity',
                        'prefix' => 'Nubit\\AdminBundle\\Entity',
                        'alias' => 'NubitAdmin',
                    ],
                ],
            ],
        ]);
    }
}
