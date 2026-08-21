<?php

declare(strict_types=1);

namespace Nubit\AdminBundle;

use Nubit\AdminBundle\Audit\AuditTrailListener;
use Nubit\AdminBundle\Audit\Controller\AuditTrailController;
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
use Nubit\AdminBundle\Command\DiscoverCommand;
use Nubit\AdminBundle\Command\PurgeAuditLogCommand;
use Nubit\AdminBundle\Command\PurgeRefreshTokensCommand;
use Nubit\AdminBundle\Command\SecurityAuditCommand;
use Nubit\AdminBundle\Controller\ChangePasswordController;
use Nubit\AdminBundle\Controller\LoginController;
use Nubit\AdminBundle\Controller\LogoutController;
use Nubit\AdminBundle\Controller\MeController;
use Nubit\AdminBundle\Controller\RefreshController;
use Nubit\AdminBundle\DependencyInjection\AnalyticsModule;
use Nubit\AdminBundle\DependencyInjection\BackupModule;
use Nubit\AdminBundle\DependencyInjection\ExportModule;
use Nubit\AdminBundle\DependencyInjection\MediaModule;
use Nubit\AdminBundle\DependencyInjection\NotificationModule;
use Nubit\AdminBundle\DependencyInjection\ObservabilityModule;
use Nubit\AdminBundle\DependencyInjection\OidcModule;
use Nubit\AdminBundle\DependencyInjection\RuntimeConfigModule;
use Nubit\AdminBundle\EmbeddedLines\Controller\EmbeddedLinesController;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRegistry;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRouteLoader;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRowSerializer;
use Nubit\AdminBundle\EventListener\SoftDeleteFilterListener;
use Nubit\AdminBundle\Export\XlsxEncoder;
use Nubit\AdminBundle\Mercure\FailSafeHub;
use Nubit\AdminBundle\Notification\EventListener\CurrentRecipientFilter;
use Nubit\AdminBundle\OpenApi\EmbeddedLinesDocumentationNormalizer;
use Nubit\AdminBundle\Session\AppProfile;
use Nubit\AdminBundle\Session\DefaultMeResponseBuilder;
use Nubit\AdminBundle\Session\MeResponseBuilderInterface;
use Nubit\AdminBundle\Tenant\AllowAllFeatureChecker;
use Nubit\AdminBundle\Tenant\SingleTenantConnectionSwitcher;
use Nubit\AdminBundle\Tenant\SingleTenantRegistry;
use Nubit\AdminBundle\Tenant\UnlimitedQuotaEnforcer;
use Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter;
use Nubit\ApiPlatform\Doctrine\Filter\GridVirtualFieldInterface;
use Nubit\ApiPlatform\Doctrine\Filter\SoftDeleteFilter;
use Nubit\ApiPlatform\Http\ApiResponseListener;
use Nubit\ApiPlatform\Http\ExceptionListener;
use Nubit\ApiPlatform\Http\GridSummaryCalculator;
use Nubit\ApiPlatform\OpenApi\TranslatedDocumentationNormalizer;
use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;
use Nubit\Platform\Notification\Contract\NotificationChannelInterface;
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
        $definition
            ->rootNode()
            ->children()
            ->scalarNode('app_profile')
            ->info(
                'Application profile: internal (single org), saas (B2B multi-tenant), hybrid (one org, multiple spaces).',
            )
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
            ->integerNode('access_token_ttl')
            ->defaultValue(3600)
            ->end()
            ->integerNode('refresh_token_ttl')
            ->defaultValue(1209600)
            ->end()
            ->booleanNode('cookie_secure')
            ->defaultTrue()
            ->end()
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
            ->scalarPrototype()
            ->end()
            ->defaultValue(['*'])
            ->end()
            ->scalarNode('hub_path')
            ->defaultValue('/.well-known/mercure')
            ->end()
            ->booleanNode('fail_safe')
            ->info(
                'Decorate the default hub so a dead Mercure never turns a successful write into a 500. HTTP requests log-and-continue; workers/console rethrow so async retries still work. Applies whenever MercureBundle is installed, regardless of "enabled".',
            )
            ->defaultTrue()
            ->end()
            ->end()
            ->end()
            ->arrayNode('oidc')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Register GET /api/auth/oidc/{provider}/redirect and /callback (authorization code + PKCE). Works against any OpenID Connect-compliant IdP (Okta, Azure AD, Google Workspace, Auth0, Keycloak…) via issuer discovery — no per-provider SDK. Requires an app-provided OidcUserResolverInterface, and OidcAuthenticator added to the firewall\'s custom_authenticators.',
            )
            ->defaultFalse()
            ->end()
            ->arrayNode('providers')
            ->useAttributeAsKey('name')
            ->arrayPrototype()
            ->children()
            ->scalarNode('issuer')
            ->info('OIDC issuer base URL — {issuer}/.well-known/openid-configuration must resolve.')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('client_id')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('client_secret')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->arrayNode('scopes')
            ->scalarPrototype()
            ->end()
            ->defaultValue(['openid', 'email', 'profile'])
            ->end()
            ->scalarNode('redirect_uri')
            ->info(
                'Must exactly match the redirect URI registered with the IdP — usually {api_base_url}/api/auth/oidc/{name}/callback.',
            )
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('post_login_redirect_uri')
            ->info('Frontend URL the browser lands on after a successful (or failed, with ?error=) login.')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->end()
            ->end()
            ->defaultValue([])
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
            ->scalarPrototype()
            ->end()
            ->defaultValue(['createdAt', 'updatedAt', 'password'])
            ->end()
            ->integerNode('purge_retention_days')
            ->info('nubit:audit:purge removes entries older than this.')
            ->defaultValue(365)
            ->end()
            ->end()
            ->end()
            ->arrayNode('observability')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info('Register privacy-safe Monolog processors and tenant-aware OpenTelemetry tracing services.')
            ->defaultFalse()
            ->end()
            ->scalarNode('redaction_hmac_key')
            ->info('HMAC key for stable confidential-value correlation. Empty means confidential hashes are dropped.')
            ->defaultValue('')
            ->end()
            ->end()
            ->end()
            ->arrayNode('analytics')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info('Persist privacy-safe typed analytics events to the transactional Doctrine outbox.')
            ->defaultFalse()
            ->end()
            ->scalarNode('redaction_hmac_key')
            ->info('HMAC key for confidential analytics properties. Empty drops properties requiring a hash.')
            ->defaultValue('')
            ->end()
            ->integerNode('deduplication_capacity')
            ->min(1)
            ->defaultValue(10000)
            ->end()
            ->integerNode('batch_size')
            ->min(1)
            ->max(1000)
            ->defaultValue(100)
            ->end()
            ->integerNode('maximum_retry_delay')
            ->min(1)
            ->defaultValue(3600)
            ->end()
            ->integerNode('retention_days')
            ->min(1)
            ->defaultValue(30)
            ->end()
            ->scalarNode('delivery_endpoint')
            ->defaultValue('')
            ->end()
            ->scalarNode('delivery_token')
            ->defaultValue('')
            ->end()
            ->floatNode('delivery_timeout')
            ->min(0.1)
            ->max(30.0)
            ->defaultValue(5.0)
            ->end()
            ->booleanNode('allow_insecure_http')
            ->defaultFalse()
            ->end()
            ->end()
            ->end()
            ->arrayNode('media')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Expose the media library: POST /api/media (multipart), Media entity, streaming route, purge command.',
            )
            ->defaultFalse()
            ->end()
            ->arrayNode('storage')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('filesystem')
            ->info(
                'Service id of a League\\Flysystem FilesystemOperator (e.g. an S3 filesystem from oneup/flysystem-bundle). Overrides local_directory.',
            )
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
            ->integerNode('max_size')
            ->info('Maximum upload size in bytes. 0 means no limit.')
            ->defaultValue(10 * 1024 * 1024)
            ->end()
            ->arrayNode('allowed_mimes')
            ->info('Allowlist of server-detected MIME types. Empty array allows all types.')
            ->scalarPrototype()
            ->end()
            ->defaultValue(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'])
            ->end()
            ->end()
            ->end()
            ->arrayNode('notification')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Register NotificationDispatcherInterface (dispatched through Messenger) and an email channel (symfony/mailer). Domain code (e.g. a workflow transition listener) calls dispatch(); app services tagged nubit.admin.notification_channel add more channels.',
            )
            ->defaultFalse()
            ->end()
            ->scalarNode('from_address')
            ->info('"From" address for the built-in email channel.')
            ->defaultValue('')
            ->end()
            ->arrayNode('in_app')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Register the Notification entity (GET /api/notifications, mercure: true) and an "in_app" channel. Maps a new table — run doctrine:migrations:diff after enabling.',
            )
            ->defaultFalse()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('backup')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Register a PostgreSQL TenantBackupRunnerInterface (pg_dump) and bin/console nubit:tenant:backup. Requires pg_dump on PATH.',
            )
            ->defaultFalse()
            ->end()
            ->arrayNode('storage')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('filesystem')
            ->info('Service id of a League\\Flysystem FilesystemOperator to store dumps in. Overrides local_directory.')
            ->defaultNull()
            ->end()
            ->scalarNode('local_directory')
            ->defaultValue('%kernel.project_dir%/var/backups')
            ->end()
            ->end()
            ->end()
            ->scalarNode('pg_dump_binary')
            ->defaultValue('pg_dump')
            ->end()
            ->integerNode('timeout_seconds')
            ->defaultValue(300)
            ->end()
            ->end()
            ->end()
            ->arrayNode('export')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Register the "xlsx" format on every ApiResource: GET ?_format=xlsx (or Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet) streams the same collection/item data as a spreadsheet. Pairs with the frontend toolbar export button gated by permissions.canExport.',
            )
            ->defaultFalse()
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
     * @param array<array-key, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->parameters()->set('nubit_admin.api.default_docs_locale', 'en');

        $services = $configurator->services()->defaults()->autowire()->autoconfigure();

        // ── Extension-point autoconfiguration ────────────────────────────────
        $container
            ->registerForAutoconfiguration(GridVirtualFieldInterface::class)
            ->addTag('nubit.api_platform.grid_virtual_field');
        $container
            ->registerForAutoconfiguration(LoginResponseDecoratorInterface::class)
            ->addTag('nubit.admin.login_response_decorator');
        $container
            ->registerForAutoconfiguration(NotificationChannelInterface::class)
            ->addTag('nubit.admin.notification_channel');
        // ── nubitio/api-platform bridge ──────────────────────────────────────
        $services->set(DataGridFilter::class);
        $services->set(GridSummaryCalculator::class);
        $services->set(ApiResponseListener::class);
        $services->set(ExceptionListener::class);

        if ($config['api']['translated_docs']) {
            $services->set(TranslatedDocumentationNormalizer::class)->decorate(
                'api_platform.hydra.normalizer.documentation',
            )->arg('$inner', service('.inner'))->arg('$apiLocale', $config['api']['docs_locale']);

            $services
                ->set(EmbeddedLinesDocumentationNormalizer::class)
                ->decorate(TranslatedDocumentationNormalizer::class)
                ->args([
                    '$inner' => service('.inner'),
                ]);
        }

        // ── Auth ─────────────────────────────────────────────────────────────
        /** @var array{secret: string, access_token_ttl: int, refresh_token_ttl: int, cookie_secure: bool} $authConfig */
        $authConfig = $config['auth'];

        $services->set(JWTManager::class)->arg('$secret', $authConfig['secret']);
        $services->alias(JWTManagerInterface::class, JWTManager::class);

        $services->set(ResponseModeResolver::class);

        $services->set(CookieFactory::class)->arg('$cookieSecure', $authConfig['cookie_secure']);

        $services->set(DefaultTokenClaimsProvider::class);
        $services->alias(TokenClaimsProviderInterface::class, DefaultTokenClaimsProvider::class);

        $services->set(DoctrineRefreshTokenStore::class);
        $services->alias(RefreshTokenStoreInterface::class, DoctrineRefreshTokenStore::class);

        $services->set(TokenGenerator::class)->arg('$accessTokenTtl', $authConfig['access_token_ttl'])->arg(
            '$refreshTokenTtl',
            $authConfig['refresh_token_ttl'],
        );

        $services->set(JWTAuthenticator::class);

        $services->set(PurgeRefreshTokensCommand::class);
        $services->set(DiscoverCommand::class);
        $services->set(SecurityAuditCommand::class);

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
            $services->set(FailSafeHub::class)->decorate(
                'mercure.hub.default',
                null,
                0,
                ContainerInterface::IGNORE_ON_INVALID_REFERENCE,
            )->arg('$inner', service('.inner'));
        }

        if ($config['mercure']['enabled']) {
            $services->set(MercureSubscriberTokenService::class)->arg(
                '$mercureJwtSecret',
                $config['mercure']['secret'],
            )->arg('$tokenTtl', $authConfig['access_token_ttl']);
            $services
                ->set(MercureCookieDecorator::class)
                ->arg('$topics', $config['mercure']['topics'])
                ->arg('$hubPath', $config['mercure']['hub_path'])
                ->tag('nubit.admin.login_response_decorator');
        }

        if ($config['media']['enabled']) {
            MediaModule::load($config['media'], $configurator, $services);
        }

        /** @var array{enabled: bool} $exportConfig */
        $exportConfig = $config['export'];
        if ($exportConfig['enabled']) {
            ExportModule::load($services);
        }

        /** @var array{enabled: bool, providers: array<string, array{issuer: string, client_id: string, client_secret: string, scopes: list<string>, redirect_uri: string, post_login_redirect_uri: string}>} $oidcConfig */
        $oidcConfig = $config['oidc'];
        if ($oidcConfig['enabled']) {
            OidcModule::load($oidcConfig['providers'], $authConfig['secret'], $services);
        }

        /** @var array{enabled: bool, from_address: string, in_app: array{enabled: bool}} $notificationConfig */
        $notificationConfig = $config['notification'];
        if ($notificationConfig['enabled']) {
            NotificationModule::load($notificationConfig, $services);
        }

        /** @var array{enabled: bool, storage: array{filesystem: ?string, local_directory: string}, pg_dump_binary: string, timeout_seconds: int} $backupConfig */
        $backupConfig = $config['backup'];
        if ($backupConfig['enabled']) {
            BackupModule::load($backupConfig, $services);
        }

        RuntimeConfigModule::load($config['runtime_config'], $configurator, $services);

        /** @var array{enabled: bool, redaction_hmac_key: string} $observabilityConfig */
        $observabilityConfig = $config['observability'];
        if ($observabilityConfig['enabled']) {
            ObservabilityModule::load($observabilityConfig, $services);
        }

        /** @var array{enabled: bool, redaction_hmac_key: string, deduplication_capacity: int, batch_size: int, maximum_retry_delay: int, retention_days: int, delivery_endpoint: string, delivery_token: string, delivery_timeout: float, allow_insecure_http: bool} $analyticsConfig */
        $analyticsConfig = $config['analytics'];
        // 'enabled' may be an unresolved %env(bool:...)% placeholder (always
        // truthy in plain PHP) — always register the services and let
        // AnalyticsPublisher check the resolved value at runtime, so the
        // env var actually gets consumed by the container.
        AnalyticsModule::load($analyticsConfig, $services);

        if ($config['audit']['enabled']) {
            $services->set(AuditTrailListener::class)->arg(
                '$ignoredFields',
                $config['audit']['ignored_fields'],
            )->tag('doctrine.event_listener', ['event' => 'onFlush'])->tag('doctrine.event_listener', [
                'event' => 'postFlush',
            ]);

            $services->set(AuditTrailController::class)->tag('controller.service_arguments');

            $services->set(PurgeAuditLogCommand::class)->arg(
                '$retentionDays',
                $config['audit']['purge_retention_days'],
            );
        }

        $services->set(DefaultMeResponseBuilder::class)->arg('$appProfile', AppProfile::from($config['app_profile']));
        $services->alias(MeResponseBuilderInterface::class, DefaultMeResponseBuilder::class);

        $services->set(LoginController::class)->tag('controller.service_arguments');
        $services->set(ChangePasswordController::class)->tag('controller.service_arguments');
        $services->set(RefreshController::class)->tag('controller.service_arguments');
        $services->set(LogoutController::class)->tag('controller.service_arguments');
        $services->set(MeController::class)->tag('controller.service_arguments');

        $services->set(EmbeddedLinesRegistry::class);
        $services->set(EmbeddedLinesRowSerializer::class);
        $services->set(EmbeddedLinesController::class)->tag('controller.service_arguments');
        $services->set(EmbeddedLinesRouteLoader::class)->tag('routing.loader');

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

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        // The Nubit HTTP client (@nubitio/core) sends plain application/json
        // request bodies. Prepend the formats so consumers get JSON support
        // out of the box — application-level api_platform.yaml still wins.
        if ($container->hasExtension('api_platform')) {
            $container->prependExtensionConfig('api_platform', [
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

        // Export (opt-in): registering "xlsx" as an api_platform format turns
        // it on for every ApiResource automatically — no per-resource config,
        // same mechanism the "json"/"jsonld" formats above use.
        if ($this->isFeatureEnabled($container, 'export') && $container->hasExtension('api_platform')) {
            $container->prependExtensionConfig('api_platform', [
                'formats' => [
                    XlsxEncoder::FORMAT => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                ],
            ]);
        }

        // Media library (opt-in): map the entity and expose it as an
        // ApiResource. Conditional on the raw config because an unconditional
        // mapping would surface the nubit_media table and /api/media routes
        // in apps that never enabled the feature.
        if ($this->isFeatureEnabled($container, 'media')) {
            $this->prependMediaMappings($container);
        }

        // Audit trail (opt-in): same reasoning — only map nubit_audit_log
        // when the feature is on. AuditLog is not an ApiResource (the plain
        // route serves it), so only the Doctrine mapping is needed.
        if ($this->isFeatureEnabled($container, 'audit') && $container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
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

        // In-app notifications (opt-in, nested under notification.in_app):
        // Notification IS an ApiResource (unlike AuditLog), so it needs both
        // the api_platform mapping path (for resource discovery) and the
        // Doctrine mapping — same two-part treatment as prependMediaMappings.
        if ($this->isFeatureEnabled($container, 'notification', 'in_app')) {
            $this->prependNotificationMappings($container);

            if ($container->hasExtension('doctrine')) {
                $container->prependExtensionConfig('doctrine', [
                    'orm' => [
                        'filters' => [
                            'nubit_notification_recipient' => [
                                'class' => CurrentRecipientFilter::class,
                                'enabled' => false, // enabled per-request by CurrentRecipientFilterListener
                            ],
                        ],
                    ],
                ]);
            }
        }

        if ($this->isFeatureEnabled($container, 'analytics') && $container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NubitAdminAnalyticsBundle' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => __DIR__ . '/Analytics/Entity',
                            'prefix' => 'Nubit\\AdminBundle\\Analytics\\Entity',
                            'alias' => 'NubitAdminAnalytics',
                        ],
                    ],
                ],
            ]);
        }

        if (!$container->hasExtension('doctrine')) {
            return;
        }

        // Soft-delete filter for #[SoftDeletable] entities (no-op without the
        // attribute). Apps can disable via nubit_admin.soft_delete: false.
        $container->prependExtensionConfig('doctrine', [
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
        $container->prependExtensionConfig('doctrine', [
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
     * Takes a path so nested toggles (`notification.in_app.enabled`) read the
     * same way as top-level ones (`export.enabled`); the last config fragment
     * that mentions the toggle wins, matching Symfony's own merge order.
     */
    private function isFeatureEnabled(ContainerBuilder $builder, string ...$path): bool
    {
        $enabled = false;
        foreach ($builder->getExtensionConfig('nubit_admin') as $config) {
            $node = $config;
            foreach ($path as $segment) {
                if (!isset($node[$segment]) || !is_array($node[$segment])) {
                    continue 2;
                }
                /** @var array<string, mixed> $node */
                $node = $node[$segment];
            }

            if (isset($node['enabled'])) {
                $enabled = (bool) $node['enabled'];
            }
        }

        return $enabled;
    }

    /**
     * Adds one bundle-owned entity directory to api_platform.mapping.paths.
     *
     * API Platform skips its project-dir defaults (src/Entity,
     * src/ApiResource, config/api_platform) as soon as mapping.paths is
     * non-empty — our prepend must not displace the app's own entities, so
     * re-add those defaults when the app relied on them.
     */
    private function prependApiPlatformMappingPath(ContainerBuilder $builder, string $entityDir): void
    {
        if (!$builder->hasExtension('api_platform')) {
            return;
        }

        $appPaths = [];
        foreach ($builder->getExtensionConfig('api_platform') as $config) {
            $appPaths = array_merge($appPaths, (array) ($config['mapping']['paths'] ?? []));
        }

        $paths = [$entityDir];

        if ($appPaths === []) {
            /** @var string $projectDir */
            $projectDir = $builder->getParameter('kernel.project_dir');
            foreach ([
                "$projectDir/config/api_platform",
                "$projectDir/src/ApiResource",
                "$projectDir/src/Entity",
            ] as $dir) {
                if (is_dir($dir)) {
                    $paths[] = $dir;
                }
            }
        }

        $builder->prependExtensionConfig('api_platform', [
            'mapping' => ['paths' => $paths],
        ]);
    }

    private function prependNotificationMappings(ContainerBuilder $builder): void
    {
        $this->prependApiPlatformMappingPath($builder, __DIR__ . '/Notification/Entity');

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NubitAdminNotificationBundle' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => __DIR__ . '/Notification/Entity',
                            'prefix' => 'Nubit\\AdminBundle\\Notification\\Entity',
                            'alias' => 'NubitAdminNotification',
                        ],
                    ],
                ],
            ]);
        }
    }

    private function prependMediaMappings(ContainerBuilder $builder): void
    {
        $this->prependApiPlatformMappingPath($builder, __DIR__ . '/Media/Entity');

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
