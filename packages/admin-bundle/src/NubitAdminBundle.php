<?php

declare(strict_types=1);

namespace Nubit\AdminBundle;

use Nubit\AdminBundle\Auth\CookieFactory;
use Nubit\AdminBundle\Auth\CsrfProtectionListener;
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
use Nubit\AdminBundle\Authorization\ScopedEntityLocator;
use Nubit\AdminBundle\Command\DiscoverCommand;
use Nubit\AdminBundle\Command\PurgeRefreshTokensCommand;
use Nubit\AdminBundle\Command\SecurityAuditCommand;
use Nubit\AdminBundle\Controller\ChangePasswordController;
use Nubit\AdminBundle\Controller\DisabledModuleController;
use Nubit\AdminBundle\Controller\LoginController;
use Nubit\AdminBundle\Controller\LogoutController;
use Nubit\AdminBundle\Controller\MeController;
use Nubit\AdminBundle\Controller\RefreshController;
use Nubit\AdminBundle\DependencyInjection\AnalyticsModule;
use Nubit\AdminBundle\DependencyInjection\AuditModule;
use Nubit\AdminBundle\DependencyInjection\AuthorizationModule;
use Nubit\AdminBundle\DependencyInjection\BackupModule;
use Nubit\AdminBundle\DependencyInjection\BundleConfig;
use Nubit\AdminBundle\DependencyInjection\Compiler\RemoveEmailChannelWithoutMailerPass;
use Nubit\AdminBundle\DependencyInjection\DocumentModule;
use Nubit\AdminBundle\DependencyInjection\ExportModule;
use Nubit\AdminBundle\DependencyInjection\IdentityModule;
use Nubit\AdminBundle\DependencyInjection\ImportModule;
use Nubit\AdminBundle\DependencyInjection\MediaModule;
use Nubit\AdminBundle\DependencyInjection\MercureModule;
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
use Nubit\AdminBundle\OpenApi\EmbeddedLinesDocumentationNormalizer;
use Nubit\AdminBundle\OpenApi\GridScaleDocumentationNormalizer;
use Nubit\AdminBundle\Resource\ResourceSegmentIndex;
use Nubit\AdminBundle\Security\BundleRouteCatalog;
use Nubit\AdminBundle\Security\PrivilegedAccess;
use Nubit\AdminBundle\Session\AppProfile;
use Nubit\AdminBundle\Session\DefaultMeResponseBuilder;
use Nubit\AdminBundle\Session\MeResponseBuilderInterface;
use Nubit\AdminBundle\Tenant\AllowAllFeatureChecker;
use Nubit\AdminBundle\Tenant\SingleTenantConnectionSwitcher;
use Nubit\AdminBundle\Tenant\SingleTenantRegistry;
use Nubit\AdminBundle\Tenant\UnlimitedQuotaEnforcer;
use Nubit\ApiPlatform\Authorization\RowScopeApplier;
use Nubit\ApiPlatform\Authorization\RowScopeRegistry;
use Nubit\ApiPlatform\Doctrine\ApproximateCounter;
use Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter;
use Nubit\ApiPlatform\Doctrine\Filter\GridVirtualFieldInterface;
use Nubit\ApiPlatform\Doctrine\Filter\SoftDeleteFilter;
use Nubit\ApiPlatform\Doctrine\GridScaleRegistry;
use Nubit\ApiPlatform\Doctrine\Money\MoneyColumns;
use Nubit\ApiPlatform\Doctrine\Type\UtcDateTimeImmutableType;
use Nubit\ApiPlatform\Http\ApiResponseListener;
use Nubit\ApiPlatform\Http\ExceptionListener;
use Nubit\ApiPlatform\Http\GridSummaryCalculator;
use Nubit\ApiPlatform\Metadata\MoneyPropertyMetadataFactory;
use Nubit\ApiPlatform\OpenApi\TranslatedDocumentationNormalizer;
use Nubit\ApiPlatform\Serializer\MoneyNormalizer;
use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;
use Nubit\Platform\Notification\Contract\NotificationChannelInterface;
use Nubit\Platform\Quota\Contract\QuotaEnforcerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Nubit\Platform\Time\TimeZoneResolver;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
    /**
     * Server-side ceiling on `itemsPerPage` for every grid, applied whether or
     * not a resource opts into `paginationClientItemsPerPage`. Unset, API
     * Platform's own default is unbounded — a client can ask for the whole
     * table in one page, which is exactly the unbounded-query risk this bundle
     * otherwise guards against with `ApproximateCounter` and cursor pagination.
     * Application-level `api_platform.yaml` still wins (this is prepended),
     * and a resource can still raise or lower it per-operation via
     * `paginationMaximumItemsPerPage`.
     */
    private const int DEFAULT_MAX_ITEMS_PER_PAGE = 200;

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
            ->scalarNode('cookie_domain')
            ->info(
                'Domain attribute for the auth/CSRF cookies. Unset (the default) makes a host-only cookie. Set to a shared parent domain (e.g. ".example.com") only when the frontend and API are deliberately split across subdomains of the same site and must share the cookies — doing so also widens which origins can read the CSRF cookie, so pair it with trusted_origins.',
            )
            ->defaultNull()
            ->end()
            ->booleanNode('csrf_protection')
            ->info(
                'Require a X-CSRF-Token header matching the CSRF_TOKEN cookie on POST/PUT/PATCH/DELETE requests authenticated via the AUTH_TOKEN/REFRESH_TOKEN cookie (double-submit policy). Bearer-token and X-Api-Key clients are never subject to it — only turn this off if CSRF is enforced some other way (e.g. at a reverse proxy).',
            )
            ->defaultTrue()
            ->end()
            ->arrayNode('trusted_origins')
            ->info(
                'Origins besides the request\'s own host allowed to present the double-submit CSRF pair. Only needed when cookie_domain is shared with a frontend served from a different host (e.g. "https://app.example.com") — every other mutating cookie-authenticated request whose Origin header disagrees with its own host is rejected regardless of a matching token.',
            )
            ->scalarPrototype()
            ->end()
            ->defaultValue([])
            ->end()
            ->end()
            ->end()
            ->arrayNode('time')
            ->addDefaultsIfNotSet()
            ->info('Storage is UTC; this configures how instants are presented.')
            ->children()
            ->scalarNode('default_timezone')
            ->info(
                'IANA identifier used when neither the user nor the tenant states one. Reported by GET /api/me so the frontend formats the same way.',
            )
            ->defaultValue('UTC')
            ->end()
            ->booleanNode('enforce_utc')
            ->info(
                'Override Doctrine datetime_immutable so timestamps are written and read as UTC regardless of the server locale. Turn off only if the application already handles this itself.',
            )
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
            ->arrayNode('identity')
            ->addDefaultsIfNotSet()
            ->info('Second factor, password recovery, invitations, API keys and active sessions.')
            ->children()
            ->booleanNode('enabled')
            ->defaultFalse()
            ->end()
            ->scalarNode('issuer')
            ->info('Name shown in the authenticator app.')
            ->defaultValue('Nubit')
            ->end()
            ->scalarNode('user_class')
            ->info(
                'FQCN of the application User entity. Required for password reset and invitations, which have to write to it. Alias IdentityUserGatewayInterface instead for anything the default gateway cannot express.',
            )
            ->defaultNull()
            ->end()
            ->scalarNode('user_identifier_property')
            ->defaultValue('email')
            ->end()
            ->arrayNode('totp')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('required_for_all')
            ->defaultFalse()
            ->end()
            ->arrayNode('required_for_roles')
            ->info('Roles that must enrol a second factor, e.g. ROLE_ADMIN.')
            ->scalarPrototype()
            ->end()
            ->defaultValue([])
            ->end()
            ->end()
            ->end()
            ->arrayNode('password_reset')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('lifetime_minutes')
            ->defaultValue(30)
            ->end()
            ->integerNode('max_attempts')
            ->info('Requests per window, counted per identity and per IP. 0 disables the limit.')
            ->defaultValue(5)
            ->end()
            ->integerNode('window_seconds')
            ->defaultValue(900)
            ->end()
            ->end()
            ->end()
            ->arrayNode('invitations')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('lifetime_days')
            ->defaultValue(7)
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('authorization')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Granular resource.action permissions: the Role entity, a voter, row scoping and permissions in GET /api/me.',
            )
            ->defaultFalse()
            ->end()
            ->booleanNode('enforce_by_default')
            ->info(
                'Give every operation without an explicit security: expression the permission it implies. Turning this off leaves the catalogue advisory — the operations stay reachable by any authenticated user.',
            )
            ->defaultTrue()
            ->end()
            ->arrayNode('super_roles')
            ->info(
                'Roles holding every permission without listing them, so nobody can lock themselves out of the authorization screen.',
            )
            ->scalarPrototype()
            ->end()
            ->defaultValue(['ROLE_SUPER_ADMIN'])
            ->end()
            ->arrayNode('exempt_resources')
            ->info('Resource FQCNs that stay reachable without a derived permission.')
            ->scalarPrototype()
            ->end()
            ->defaultValue([])
            ->end()
            ->end()
            ->end()
            ->arrayNode('documents')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Issue PDF documents for #[Printable] resources: POST /api/documents/{resource}/{id}, the nubit_issued_document table and a download route. Needs WeasyPrint on PATH.',
            )
            ->defaultFalse()
            ->end()
            ->booleanNode('async')
            ->info(
                'Render through Messenger instead of inline. The issue call returns a pending document and the download route answers 202 until the worker finishes. Route RenderDocument to a transport.',
            )
            ->defaultFalse()
            ->end()
            ->scalarNode('directory')
            ->info('Sub-directory inside the storage where issued documents land.')
            ->defaultValue('documents')
            ->end()
            ->scalarNode('weasyprint_binary')
            ->info('Path to the WeasyPrint executable.')
            ->defaultValue('weasyprint')
            ->end()
            ->arrayNode('storage')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('filesystem')
            ->info(
                'Service id of a League\\Flysystem FilesystemOperator. Overrides local_directory. Issued documents are records — point this at storage with a retention policy.',
            )
            ->defaultNull()
            ->end()
            ->scalarNode('local_directory')
            ->defaultValue('%kernel.project_dir%/var/documents')
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('imports')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Expose spreadsheet import for #[Importable] resources: POST /api/imports/{resource} (upload + dry run), PATCH to correct the mapping, POST /confirm to apply. Adds the nubit_import_session table.',
            )
            ->defaultFalse()
            ->end()
            ->scalarNode('directory')
            ->info(
                'Where uploaded files are kept while a session is open. They are the evidence of what was imported — set a retention policy.',
            )
            ->defaultValue('%kernel.project_dir%/var/imports')
            ->end()
            ->scalarNode('default_currency')
            ->info('Currency assumed for money columns whose cells carry no currency code.')
            ->defaultValue('EUR')
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
            ->arrayNode('grid')
            ->addDefaultsIfNotSet()
            ->info('How large grids are read. See #[GridScale] for the per-resource decision.')
            ->children()
            ->booleanNode('approximate_count')
            ->info(
                'Answer an unfiltered total from the PostgreSQL planner statistics instead of COUNT(*). Wrong by a few percent between vacuums, which matters far less than the full scan it replaces.',
            )
            ->defaultFalse()
            ->end()
            ->integerNode('approximate_count_threshold')
            ->info('Only estimate above this many rows; below it an exact count is cheap.')
            ->defaultValue(100000)
            ->end()
            ->end()
            ->end()
            ->arrayNode('export')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info(
                'Enable the "xlsx" export format. Resources opt in individually with #[Exportable]: their GET endpoints then answer /resource.xlsx or Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet with a spreadsheet of every row matching the query, pagination removed. Resources without the attribute answer 406 and do not advertise the format. Requires phpoffice/phpspreadsheet with ext-zip and ext-gd. Pairs with the frontend toolbar button, gated separately by permissions.canExport.',
            )
            ->defaultFalse()
            ->end()
            ->booleanNode('queued')
            ->info(
                'Queue exports above the inline limit instead of streaming them in the request. Adds the nubit_export_job table and CSV output — PhpSpreadsheet builds a workbook in memory, so a very large XLSX is the failure queueing exists to avoid. Route RunExport to a transport.',
            )
            ->defaultFalse()
            ->end()
            ->scalarNode('directory')
            ->info('Where queued export files are written.')
            ->defaultValue('%kernel.project_dir%/var/exports')
            ->end()
            ->integerNode('inline_limit')
            ->info('Rows above which an export is queued. Overridden per resource by #[GridScale].')
            ->defaultValue(5000)
            ->end()
            ->enumNode('queued_format')
            ->info(
                'Format for queued exports. "xlsx" streams through openspout/openspout — PhpSpreadsheet cannot, it builds the whole workbook in memory first. "csv" needs no dependency.',
            )
            ->values(['xlsx', 'csv'])
            ->defaultValue('xlsx')
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
        // Row scoping is registered outside the authorization module because
        // the queued export needs it whether or not permissions are on: a
        // worker has no session, and that is exactly where scope gets dropped.
        $services->set(ResourceSegmentIndex::class);
        $services->set(RowScopeRegistry::class);
        $services->set(RowScopeApplier::class);
        $services->set(ScopedEntityLocator::class);

        $services->set(GridScaleRegistry::class);
        $services->set(ApproximateCounter::class)->arg('$connection', service('doctrine.dbal.default_connection'));
        $services->set(DataGridFilter::class)->arg('$gridScales', service(GridScaleRegistry::class));

        // Publishing how a resource expects to be read is what lets the grid
        // paginate the way the backend intends rather than the way it always has.
        $services->set(GridScaleDocumentationNormalizer::class)->decorate(
            'api_platform.hydra.normalizer.documentation',
            priority: -40,
        )->arg('$inner', service('.inner'));
        $services->set(GridSummaryCalculator::class)->arg('$filterLocator', service('api_platform.filter_locator'));
        $services->set(ApiResponseListener::class)->arg('$gridScales', service(GridScaleRegistry::class))->arg(
            '$approximateCounter',
            service(ApproximateCounter::class),
        );
        $services->set(ExceptionListener::class);

        /** @var array{default_timezone: string, enforce_utc: bool} $timeConfig */
        $timeConfig = $config['time'];
        $services->set(TimeZoneResolver::class)->arg('$defaultTimeZone', $timeConfig['default_timezone']);

        // Money: exact amounts on the wire, and a documented format so the
        // frontend renders one currency field instead of three raw columns.
        $services->set(MoneyNormalizer::class)->tag('serializer.normalizer', ['priority' => 100]);
        $services->set(MoneyPropertyMetadataFactory::class)->decorate(
            'api_platform.metadata.property.metadata_factory',
            null,
            30,
        )->arg('$decorated', service('.inner'));

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
        /**
         * @var array{
         *     secret: string,
         *     access_token_ttl: int,
         *     refresh_token_ttl: int,
         *     cookie_secure: bool,
         *     cookie_domain: ?string,
         *     csrf_protection: bool,
         *     trusted_origins: list<string>,
         * } $authConfig
         */
        $authConfig = $config['auth'];

        $services->set(JWTManager::class)->arg('$secret', $authConfig['secret']);
        $services->alias(JWTManagerInterface::class, JWTManager::class);

        $services->set(ResponseModeResolver::class);

        $services->set(CookieFactory::class)->arg('$cookieSecure', $authConfig['cookie_secure'])->arg(
            '$cookieDomain',
            $authConfig['cookie_domain'],
        );

        $services->set(DefaultTokenClaimsProvider::class);
        $services->alias(TokenClaimsProviderInterface::class, DefaultTokenClaimsProvider::class);

        $services->set(DoctrineRefreshTokenStore::class);
        $services->alias(RefreshTokenStoreInterface::class, DoctrineRefreshTokenStore::class);

        $services->set(TokenGenerator::class)->arg('$accessTokenTtl', $authConfig['access_token_ttl'])->arg(
            '$refreshTokenTtl',
            $authConfig['refresh_token_ttl'],
        );

        // The TOTP badge is only attached when the identity module is on:
        // a badge nothing resolves fails the whole passport.
        /** @var array{enabled: bool} $identityEnabled Read here because the authenticator is registered above the module. */
        $identityEnabled = $config['identity'];
        $services->set(JWTAuthenticator::class)->arg('$secondFactorEnabled', $identityEnabled['enabled']);

        $services->set(CsrfProtectionListener::class)->arg('$enabled', $authConfig['csrf_protection'])->arg(
            '$trustedOrigins',
            $authConfig['trusted_origins'],
        );

        $services->set(PurgeRefreshTokensCommand::class);
        $services->set(DiscoverCommand::class);
        $services->set(SecurityAuditCommand::class)->tag('console.command');

        if ($config['soft_delete']) {
            $services->set(SoftDeleteFilterListener::class);
        }

        /** @var array{enabled: bool, fail_safe: bool, secret: string, topics: list<string>, hub_path: string} $mercureConfig */
        $mercureConfig = $config['mercure'];
        MercureModule::load($mercureConfig, $authConfig['access_token_ttl'], $services);

        if ($config['media']['enabled']) {
            MediaModule::load($config['media'], $configurator, $services);
        }

        /** @var array{enabled: bool, issuer: string, totp: array{required_for_all: bool, required_for_roles: list<string>}, password_reset: array{lifetime_minutes: int, max_attempts: int, window_seconds: int}, invitations: array{lifetime_days: int}, user_class: ?string, user_identifier_property: string} $identityConfig */
        $identityConfig = $config['identity'];
        if ($identityConfig['enabled']) {
            IdentityModule::load($identityConfig, $services);
        }

        /** @var array{enabled: bool, enforce_by_default: bool, super_roles: list<string>, exempt_resources: list<string>} $authorizationConfig */
        $authorizationConfig = $config['authorization'];
        if ($authorizationConfig['enabled']) {
            AuthorizationModule::load($authorizationConfig, $services);
        }

        /** @var array{enabled: bool, async: bool, directory: string, weasyprint_binary: string, storage: array{filesystem: ?string, local_directory: string}} $documentConfig */
        $documentConfig = $config['documents'];
        if ($documentConfig['enabled']) {
            DocumentModule::load($documentConfig, $configurator, $services, $container);
        }

        /** @var array{enabled: bool, directory: string, default_currency: string} $importConfig */
        $importConfig = $config['imports'];
        if ($importConfig['enabled']) {
            ImportModule::load($importConfig, $services, $container);
        }

        /** @var array{enabled: bool, queued: bool, directory: string, inline_limit: int, queued_format: string} $exportConfig */
        $exportConfig = $config['export'];
        if ($exportConfig['enabled']) {
            ExportModule::load($exportConfig, $services);
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

        /** @var array{enabled: bool, ignored_fields: list<string>, purge_retention_days: int} $auditConfig */
        $auditConfig = $config['audit'];
        if ($auditConfig['enabled']) {
            AuditModule::load($auditConfig, $services);
        }

        $services->set(DefaultMeResponseBuilder::class)->arg('$appProfile', AppProfile::from($config['app_profile']));
        $services->alias(MeResponseBuilderInterface::class, DefaultMeResponseBuilder::class);

        $services->set(LoginController::class)->tag('controller.service_arguments');
        $services->set(ChangePasswordController::class)->tag('controller.service_arguments');
        $services->set(RefreshController::class)->tag('controller.service_arguments');
        $services->set(LogoutController::class)->tag('controller.service_arguments');
        $services->set(MeController::class)->tag('controller.service_arguments');
        $services->set(PrivilegedAccess::class);

        self::stubDisabledModuleControllers($services, $config);

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

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RemoveEmailChannelWithoutMailerPass());
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        // The Nubit HTTP client (@nubitio/core) sends plain application/json
        // request bodies. Prepend the formats so consumers get JSON support
        // out of the box — application-level api_platform.yaml still wins.
        if ($container->hasExtension('api_platform')) {
            $formats = [
                'json' => ['application/json'],
                'jsonld' => ['application/ld+json'],
            ];

            // Export (opt-in), registered in the same call and deliberately
            // last: API Platform answers a request carrying no Accept header
            // with the *first* configured format. A separate prependExtensionConfig
            // would land ahead of these — prepending reverses the order — and a
            // grid asking for rows would be handed a spreadsheet it cannot parse.
            if (BundleConfig::isFeatureEnabled($container, 'export')) {
                $formats[XlsxEncoder::FORMAT] = [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ];
            }

            $container->prependExtensionConfig('api_platform', [
                'formats' => $formats,
                'docs_formats' => [
                    'jsonld' => ['application/ld+json'],
                    'jsonopenapi' => ['application/vnd.openapi+json'],
                    'json' => ['application/json'],
                    'html' => ['text/html'],
                ],
                'defaults' => [
                    'pagination_maximum_items_per_page' => self::DEFAULT_MAX_ITEMS_PER_PAGE,
                ],
            ]);
        }

        // Each optional module owns its own mapping (and, where relevant,
        // ApiResource path) and decides for itself, from the raw config, when
        // it applies — nothing here has to know which flag gates which
        // module, only that every one of them gets a chance to prepend.
        DocumentModule::prepend($container);
        IdentityModule::prepend($container);
        AuthorizationModule::prepend($container);
        ExportModule::prepend($container);
        ImportModule::prepend($container);
        MediaModule::prepend($container);
        AuditModule::prepend($container);
        NotificationModule::prependInApp($container);
        AnalyticsModule::prepend($container);

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

        // One meaning per timestamp column. Doctrine's stock type both writes
        // and reads in the server's local zone, so two deployments of the same
        // application disagree about what a stored instant was.
        if (BundleConfig::readBoolean($container, ['time', 'enforce_utc'], default: true)) {
            $container->prependExtensionConfig('doctrine', [
                'dbal' => ['types' => ['datetime_immutable' => UtcDateTimeImmutableType::class]],
            ]);
        }

        // Map the money embeddable. It adds no table of its own — an embeddable
        // is only columns on the entity that uses it — but Doctrine still has to
        // know the class, and an application should not have to discover that by
        // hitting a mapping exception the first time it stores an amount.
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'NubitMoney' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => dirname((string) (new \ReflectionClass(MoneyColumns::class))->getFileName()),
                        'prefix' => 'Nubit\\ApiPlatform\\Doctrine\\Money',
                        'alias' => 'NubitMoney',
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
     * Routes for optional modules live in one file so applications do not
     * import them per feature. When the module is off the real controller is
     * not a service, which used to 500. These stubs answer 404 instead.
     *
     * @param array<array-key, mixed> $config
     */
    private static function stubDisabledModuleControllers(DefaultsConfigurator $services, array $config): void
    {
        /** @var array<string, bool> $enabled */
        $enabled = [
            'identity' => (bool) ($config['identity']['enabled'] ?? false),
            'documents' => (bool) ($config['documents']['enabled'] ?? false),
            'imports' => (bool) ($config['imports']['enabled'] ?? false),
            'media' => (bool) ($config['media']['enabled'] ?? false),
            'oidc' => (bool) ($config['oidc']['enabled'] ?? false),
            'audit' => (bool) ($config['audit']['enabled'] ?? false),
            'export_queued' =>
                (bool) ($config['export']['enabled'] ?? false) && (bool) ($config['export']['queued'] ?? false),
        ];

        foreach (BundleRouteCatalog::controllersByModule() as $module => $classes) {
            if ($enabled[$module] ?? false) {
                continue;
            }

            foreach ($classes as $class) {
                $services->set($class)->class(DisabledModuleController::class)->tag('controller.service_arguments');
            }
        }
    }
}
