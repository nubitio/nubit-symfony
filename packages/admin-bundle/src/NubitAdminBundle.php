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
use Nubit\AdminBundle\Auth\MercureCookieDecorator;
use Nubit\AdminBundle\Auth\MercureSubscriberTokenService;
use Nubit\AdminBundle\Auth\RefreshTokenStoreInterface;
use Nubit\AdminBundle\Auth\ResponseModeResolver;
use Nubit\AdminBundle\Auth\TokenClaimsProviderInterface;
use Nubit\AdminBundle\Auth\TokenGenerator;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Nubit\AdminBundle\Audit\AuditTrailListener;
use Nubit\AdminBundle\Audit\Controller\AuditTrailController;
use Nubit\AdminBundle\Command\PurgeAuditLogCommand;
use Nubit\AdminBundle\Command\PurgeMediaCommand;
use Nubit\AdminBundle\Command\PurgeRefreshTokensCommand;
use Nubit\AdminBundle\Controller\ChangePasswordController;
use Nubit\AdminBundle\Controller\LoginController;
use Nubit\AdminBundle\Controller\LogoutController;
use Nubit\AdminBundle\Controller\MeController;
use Nubit\AdminBundle\Controller\RefreshController;
use Nubit\AdminBundle\Controller\RuntimeConfigController;
use Nubit\AdminBundle\EmbeddedLines\Controller\EmbeddedLinesController;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRegistry;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRouteLoader;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRowSerializer;
use Nubit\AdminBundle\Runtime\NullRuntimeConfigProvider;
use Nubit\AdminBundle\Runtime\RuntimeConfigProviderInterface;
use Nubit\AdminBundle\Session\AppProfile;
use Nubit\AdminBundle\Session\DefaultMeResponseBuilder;
use Nubit\AdminBundle\Session\MeResponseBuilderInterface;
use Nubit\AdminBundle\EventListener\SoftDeleteFilterListener;
use Nubit\AdminBundle\Media\Controller\MediaFileController;
use Nubit\AdminBundle\Media\Controller\MediaUploadController;
use Nubit\AdminBundle\Media\MediaStorage;
use Nubit\AdminBundle\Media\MediaUrlResolverInterface;
use Nubit\AdminBundle\Media\RouteMediaUrlResolver;
use Nubit\AdminBundle\Media\Serializer\MediaNormalizer;
use Nubit\AdminBundle\Media\State\MediaSoftDeleteProcessor;
use Nubit\AdminBundle\Mercure\FailSafeHub;
use Nubit\AdminBundle\Tenant\AllowAllFeatureChecker;
use Nubit\AdminBundle\Tenant\SingleTenantConnectionSwitcher;
use Nubit\AdminBundle\Tenant\SingleTenantRegistry;
use Nubit\AdminBundle\Tenant\UnlimitedQuotaEnforcer;
use Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter;
use Nubit\ApiPlatform\Doctrine\Filter\GridVirtualFieldInterface;
use Nubit\ApiPlatform\Doctrine\Filter\SoftDeleteFilter;
use Nubit\ApiPlatform\Http\ApiResponseListener;
use Nubit\ApiPlatform\Http\GridSummaryCalculator;
use Nubit\ApiPlatform\Http\ExceptionListener;
use Nubit\ApiPlatform\OpenApi\TranslatedDocumentationNormalizer;
use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;
use Nubit\Platform\Filesystem\FileManager;
use Nubit\Platform\Quota\Contract\QuotaEnforcerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
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
                ->scalarNode('app_profile')
                    ->info('Application profile: internal (single org), saas (B2B multi-tenant), hybrid (one org, multiple spaces).')
                    ->defaultValue('internal')
                    ->validate()
                        ->ifNotInArray(['internal', 'saas', 'hybrid'])
                        ->thenInvalid('Invalid app_profile %s')
                    ->end()
                ->end()
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
                ->arrayNode('mercure')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Issue a Mercure subscriber JWT cookie on login/refresh.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('secret')
                            ->info('Mercure hub subscriber JWT secret.')
                            ->defaultValue('%env(MERCURE_JWT_SECRET)%')
                        ->end()
                        ->arrayNode('topics')
                            ->info('Topic selectors the subscriber token grants.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['*'])
                        ->end()
                        ->scalarNode('hub_path')->defaultValue('/.well-known/mercure')->end()
                        ->booleanNode('fail_safe')
                            ->info('Decorate the default hub so a dead Mercure never turns a successful write into a 500. HTTP requests log-and-continue; workers/console rethrow so async retries still work. Applies whenever MercureBundle is installed, regardless of "enabled".')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('audit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Record field-level diffs of #[Auditable] entities and expose GET /api/audit-trail/{resource}/{id}.')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('ignored_fields')
                            ->info('Entity fields excluded from the recorded diffs.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['createdAt', 'updatedAt', 'password'])
                        ->end()
                        ->integerNode('purge_retention_days')
                            ->info('nubit:audit:purge removes entries older than this.')
                            ->defaultValue(365)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('media')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Expose the media library: POST /api/media (multipart), Media entity, streaming route, purge command.')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('storage')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('filesystem')
                                    ->info('Service id of a League\\Flysystem FilesystemOperator (e.g. an S3 filesystem from oneup/flysystem-bundle). Overrides local_directory.')
                                    ->defaultNull()
                                ->end()
                                ->scalarNode('local_directory')
                                    ->info('Root directory of the default local storage.')
                                    ->defaultValue('%kernel.project_dir%/var/uploads')
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('directory')
                            ->info('Sub-directory inside the storage where uploads land.')
                            ->defaultValue('media')
                        ->end()
                        ->integerNode('purge_retention_days')
                            ->info('nubit:media:purge removes media soft-deleted longer ago than this.')
                            ->defaultValue(30)
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('runtime_config')
                    ->info('Expose GET /api/runtime-config (opt-in; payload from RuntimeConfigProviderInterface).')
                    ->defaultFalse()
                ->end()
                ->booleanNode('soft_delete')
                    ->info('Register the Doctrine filter hiding #[SoftDeletable] rows.')
                    ->defaultTrue()
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
        $services->set(GridSummaryCalculator::class);
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

        $services->set(PurgeRefreshTokensCommand::class);

        if ($config['soft_delete']) {
            $services->set(SoftDeleteFilterListener::class);
        }

        // Fail-safe hub: independent of mercure.enabled (which only gates the
        // subscriber cookie) — it matters to ANY app with mercure: true
        // resources. class_exists, NOT hasExtension: loadExtension runs in a
        // per-extension temporary container that only knows nubit_admin, so
        // hasExtension is always false here. IGNORE_ON_INVALID_REFERENCE skips
        // the decoration when MercureBundle is installed but no default hub is
        // configured (apps with custom hub names decorate manually).
        if ($config['mercure']['fail_safe'] && class_exists('Symfony\\Bundle\\MercureBundle\\MercureBundle')) {
            $services->set(FailSafeHub::class)
                ->decorate('mercure.hub.default', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
                ->arg('$inner', service('.inner'));
        }

        if ($config['mercure']['enabled']) {
            $services->set(MercureSubscriberTokenService::class)
                ->arg('$mercureJwtSecret', $config['mercure']['secret'])
                ->arg('$tokenTtl', $config['auth']['access_token_ttl']);
            $services->set(MercureCookieDecorator::class)
                ->arg('$topics', $config['mercure']['topics'])
                ->arg('$hubPath', $config['mercure']['hub_path'])
                ->tag('nubit.admin.login_response_decorator');
        }

        if ($config['media']['enabled']) {
            $this->loadMedia($config['media'], $container, $services);
        }

        $this->loadRuntimeConfig($config['runtime_config'], $container, $services);

        if ($config['audit']['enabled']) {
            $services->set(AuditTrailListener::class)
                ->arg('$ignoredFields', $config['audit']['ignored_fields'])
                ->tag('doctrine.event_listener', ['event' => 'onFlush'])
                ->tag('doctrine.event_listener', ['event' => 'postFlush']);

            $services->set(AuditTrailController::class)->tag('controller.service_arguments');

            $services->set(PurgeAuditLogCommand::class)
                ->arg('$retentionDays', $config['audit']['purge_retention_days']);
        }

        $services->set(DefaultMeResponseBuilder::class)
            ->arg('$appProfile', AppProfile::from($config['app_profile']));
        $services->alias(MeResponseBuilderInterface::class, DefaultMeResponseBuilder::class);

        $services->set(LoginController::class)->tag('controller.service_arguments');
        $services->set(ChangePasswordController::class)->tag('controller.service_arguments');
        $services->set(RefreshController::class)->tag('controller.service_arguments');
        $services->set(LogoutController::class)->tag('controller.service_arguments');
        $services->set(MeController::class)->tag('controller.service_arguments');

        $services->set(EmbeddedLinesRegistry::class);
        $services->set(EmbeddedLinesRowSerializer::class);
        $services->set(EmbeddedLinesController::class)->tag('controller.service_arguments');
        $services->set(EmbeddedLinesRouteLoader::class)
            ->tag('routing.loader');

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

    private function loadRuntimeConfig(bool $enabled, ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $container->parameters()->set('nubit_admin.runtime_config.enabled', $enabled);

        $services->set(NullRuntimeConfigProvider::class);
        $services->alias(RuntimeConfigProviderInterface::class, NullRuntimeConfigProvider::class);

        $services->set(RuntimeConfigController::class)
            ->arg('$enabled', $enabled)
            ->tag('controller.service_arguments');
    }

    /**
     * @param array{
     *     storage: array{filesystem: ?string, local_directory: string},
     *     directory: string,
     *     purge_retention_days: int,
     * } $config
     */
    private function loadMedia(array $config, ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $container->parameters()->set('nubit_admin.media.directory', $config['directory']);

        if ($config['storage']['filesystem'] !== null) {
            $services->alias('nubit_admin.media.filesystem', $config['storage']['filesystem']);
        } else {
            $services->set('nubit_admin.media.local_adapter', LocalFilesystemAdapter::class)
                ->arg('$location', $config['storage']['local_directory']);
            $services->set('nubit_admin.media.filesystem', Filesystem::class)
                ->arg('$adapter', service('nubit_admin.media.local_adapter'));
        }

        // Bundle-scoped FileManager so apps keep their own FileManager (with a
        // different filesystem) without colliding with the media storage.
        $services->set('nubit_admin.media.file_manager', FileManager::class)
            ->arg('$defaultFilesystem', service('nubit_admin.media.filesystem'));

        $services->set(MediaStorage::class)
            ->arg('$fileManager', service('nubit_admin.media.file_manager'))
            ->arg('$directory', $config['directory']);

        $services->set(RouteMediaUrlResolver::class);
        $services->alias(MediaUrlResolverInterface::class, RouteMediaUrlResolver::class);

        $services->set(MediaNormalizer::class);
        $services->set(MediaSoftDeleteProcessor::class);

        $services->set(MediaUploadController::class)->tag('controller.service_arguments');
        $services->set(MediaFileController::class)->tag('controller.service_arguments');

        $services->set(PurgeMediaCommand::class)
            ->arg('$retentionDays', $config['purge_retention_days']);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The Nubit HTTP client (@nubitio/core) sends plain application/json
        // request bodies. Prepend the formats so consumers get JSON support
        // out of the box — application-level api_platform.yaml still wins.
        if ($builder->hasExtension('api_platform')) {
            $builder->prependExtensionConfig('api_platform', [
                'formats' => [
                    'json' => ['application/json'],
                    'jsonld' => ['application/ld+json'],
                ],
                'docs_formats' => [
                    'jsonld' => ['application/ld+json'],
                    'jsonopenapi' => ['application/vnd.openapi+json'],
                    'json' => ['application/json'],
                    'html' => ['text/html'],
                ],
            ]);
        }

        // Media library (opt-in): map the entity and expose it as an
        // ApiResource. Conditional on the raw config because an unconditional
        // mapping would surface the nubit_media table and /api/media routes
        // in apps that never enabled the feature.
        if ($this->isFeatureEnabled($builder, 'media')) {
            $this->prependMediaMappings($builder);
        }

        // Audit trail (opt-in): same reasoning — only map nubit_audit_log
        // when the feature is on. AuditLog is not an ApiResource (the plain
        // route serves it), so only the Doctrine mapping is needed.
        if ($this->isFeatureEnabled($builder, 'audit') && $builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NubitAdminAuditBundle' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => __DIR__ . '/Audit/Entity',
                            'prefix' => 'Nubit\\AdminBundle\\Audit\\Entity',
                            'alias' => 'NubitAdminAudit',
                        ],
                    ],
                ],
            ]);
        }

        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        // Soft-delete filter for #[SoftDeletable] entities (no-op without the
        // attribute). Apps can disable via nubit_admin.soft_delete: false.
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'filters' => [
                    'nubit_soft_delete' => [
                        'class' => SoftDeleteFilter::class,
                        'enabled' => false, // enabled per-request by SoftDeleteFilterListener
                    ],
                ],
            ],
        ]);

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

    /**
     * Reads the raw (pre-processing) bundle config: prependExtension runs
     * before configuration is processed, so this is the only signal available.
     */
    private function isFeatureEnabled(ContainerBuilder $builder, string $feature): bool
    {
        $enabled = false;
        foreach ($builder->getExtensionConfig('nubit_admin') as $config) {
            if (isset($config[$feature]['enabled'])) {
                $enabled = (bool) $config[$feature]['enabled'];
            }
        }

        return $enabled;
    }

    private function prependMediaMappings(ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('api_platform')) {
            $appPaths = [];
            foreach ($builder->getExtensionConfig('api_platform') as $config) {
                $appPaths = array_merge($appPaths, (array) ($config['mapping']['paths'] ?? []));
            }

            $paths = [__DIR__ . '/Media/Entity'];

            // API Platform skips its project-dir defaults (src/Entity,
            // src/ApiResource, config/api_platform) as soon as mapping.paths
            // is non-empty — our prepend must not displace the app's own
            // entities, so re-add those defaults when the app relied on them.
            if ($appPaths === []) {
                /** @var string $projectDir */
                $projectDir = $builder->getParameter('kernel.project_dir');
                foreach (["$projectDir/config/api_platform", "$projectDir/src/ApiResource", "$projectDir/src/Entity"] as $dir) {
                    if (is_dir($dir)) {
                        $paths[] = $dir;
                    }
                }
            }

            $builder->prependExtensionConfig('api_platform', [
                'mapping' => ['paths' => $paths],
            ]);
        }

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NubitAdminMediaBundle' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => __DIR__ . '/Media/Entity',
                            'prefix' => 'Nubit\\AdminBundle\\Media\\Entity',
                            'alias' => 'NubitAdminMedia',
                        ],
                    ],
                ],
            ]);
        }
    }
}
