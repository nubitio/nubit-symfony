<?php

declare(strict_types=1);

namespace Nubit\TenantBundle;

use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantDescriptorRegistryInterface;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Nubit\Platform\Quota\Contract\QuotaEnforcerInterface;
use Nubit\TenantBundle\Command\TenantListCommand;
use Nubit\TenantBundle\Contract\QuotaUsageProviderInterface;
use Nubit\TenantBundle\Contract\TenantDatabaseUrlProviderInterface;
use Nubit\TenantBundle\Contract\TenantDatabaseConnectionSwitcherInterface;
use Nubit\TenantBundle\Contract\TenantIsolationTargetProviderInterface;
use Nubit\TenantBundle\Contract\TenantSchemaConnectionSwitcherInterface;
use Nubit\TenantBundle\Doctrine\Filter\TenantFilter;
use Nubit\TenantBundle\Entity\Tenant;
use Nubit\TenantBundle\EventListener\QuotaEnforcementListener;
use Nubit\TenantBundle\EventListener\TenantRequestListener;
use Nubit\TenantBundle\EventListener\TenantStampListener;
use Nubit\TenantBundle\Provider\RegistryTenantDatabaseUrlProvider;
use Nubit\TenantBundle\Quota\CompositeQuotaUsageProvider;
use Nubit\TenantBundle\Quota\FeatureQuotaEnforcer;
use Nubit\TenantBundle\Quota\QuotaResourceRegistry;
use Nubit\TenantBundle\Registry\DoctrineTenantRegistry;
use Nubit\TenantBundle\Resolver\CompositeTenantResolver;
use Nubit\TenantBundle\Resolver\HeaderTenantResolver;
use Nubit\TenantBundle\Resolver\JwtClaimTenantResolver;
use Nubit\TenantBundle\Resolver\SubdomainTenantResolver;
use Nubit\TenantBundle\Resolver\TenantResolverInterface;
use Nubit\TenantBundle\Resolver\UserTenantResolver;
use Nubit\TenantBundle\Switcher\ColumnTenantConnectionSwitcher;
use Nubit\TenantBundle\Switcher\DatabaseTenantConnectionSwitcher;
use Nubit\TenantBundle\Switcher\PostgresSchemaTenantConnectionSwitcher;
use Nubit\TenantBundle\Switcher\TenantRoutingConnectionSwitcher;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Opt-in column-tenant kit. Install alongside nubitio/admin-bundle and enable
 * when the app profile is saas or hybrid.
 */
final class NubitTenantBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Enable tenant resolution, Doctrine filter, and registry wiring.')
                    ->defaultFalse()
                ->end()
                ->enumNode('isolation')
                    ->values(['column', 'database', 'schema', 'hybrid'])
                    ->defaultValue('column')
                ->end()
                ->scalarNode('tenant_connection')
                    ->info('Doctrine connection name switched in database isolation mode.')
                    ->defaultValue('default')
                ->end()
                ->scalarNode('control_plane_connection')
                    ->info('Doctrine connection for tenant registry lookups in database isolation mode.')
                    ->defaultValue('default')
                ->end()
                ->scalarNode('schema_prefix')
                    ->info('Lowercase PostgreSQL identifier prefix used with the resolved positive tenant ID.')
                    ->defaultValue('tenant_')
                ->end()
                ->arrayNode('base_schemas')
                    ->info('Explicit base PostgreSQL search_path after the tenant schema.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['public'])
                ->end()
                ->booleanNode('quotas_enabled')
                    ->info('Enforce plan limits via FeatureChecker entitlements and QuotaUsageProvider implementations.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('resolution')
                    ->info('Ordered tenant resolution strategies: user, jwt_claim, header, subdomain.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['user', 'jwt_claim'])
                ->end()
                ->scalarNode('tenant_entity')
                    ->info('FQCN of the tenant root entity used by the registry and self-filter.')
                    ->defaultValue(Tenant::class)
                ->end()
                ->arrayNode('unscoped_entities')
                    ->info('Explicit allowlist of infrastructure entity FQCNs that remain globally visible in column isolation. All other unscoped entities fail closed.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('jwt_secret')
                    ->info('Secret for jwt_claim resolution. Defaults to %env(APP_SECRET)%.')
                    ->defaultValue('%env(APP_SECRET)%')
                ->end()
                ->scalarNode('jwt_id_claim')->defaultValue('tenantId')->end()
                ->scalarNode('jwt_name_claim')->defaultValue('tenantName')->end()
                ->scalarNode('tenant_header')->defaultValue('X-Tenant-Id')->end()
                ->scalarNode('base_domain')
                    ->info('Base domain for subdomain resolution (e.g. example.com).')
                    ->defaultNull()
                ->end()
                ->booleanNode('rls_enabled')
                    ->info('Set PostgreSQL app.tenant_id per request (requires RLS policies).')
                    ->defaultFalse()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isEnabled($builder) || !$builder->hasExtension('doctrine')) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'filters' => [
                    TenantFilter::NAME => [
                        'class' => TenantFilter::class,
                        'enabled' => false,
                    ],
                ],
            ],
        ]);
    }

    private function isEnabled(ContainerBuilder $builder): bool
    {
        $configs = $builder->getExtensionConfig('nubit_tenant');

        foreach (array_reverse($configs) as $config) {
            if (isset($config['enabled'])) {
                return (bool) $config['enabled'];
            }
        }

        return false;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set('nubit_tenant.enabled', $config['enabled']);

        if (!$config['enabled']) {
            return;
        }

        $services = $container->services();
        $services->defaults()
            ->autowire()
            ->autoconfigure();

        $builder->registerForAutoconfiguration(QuotaUsageProviderInterface::class)
            ->addTag('nubit.quota_usage_provider');

        $this->registerResolvers($config, $services);
        $this->registerCoreServices($config, $services, $builder);
    }

    /**
     * @param array{
     *     enabled: bool,
     *     isolation: string,
     *     tenant_connection: string,
     *     control_plane_connection: string,
     *     schema_prefix: string,
     *     base_schemas: list<string>,
     *     quotas_enabled: bool,
     *     resolution: list<string>,
     *     tenant_entity: string,
     *     unscoped_entities: list<string>,
     *     jwt_secret: string,
     *     jwt_id_claim: string,
     *     jwt_name_claim: string,
     *     tenant_header: string,
     *     base_domain: ?string,
     *     rls_enabled: bool,
     * } $config
     */
    private function registerResolvers(array $config, ServicesConfigurator $services): void
    {
        $services->set(UserTenantResolver::class);
        $services->set(JwtClaimTenantResolver::class)
            ->arg('$jwtSecret', $config['jwt_secret'])
            ->arg('$idClaim', $config['jwt_id_claim'])
            ->arg('$nameClaim', $config['jwt_name_claim']);
        $services->set(HeaderTenantResolver::class)
            ->arg('$header', $config['tenant_header']);
        $services->set(SubdomainTenantResolver::class)
            ->arg('$baseDomain', $config['base_domain'] ?? '');

        $resolverRefs = [];
        foreach ($config['resolution'] as $strategy) {
            $resolverRefs[] = match ($strategy) {
                'user' => service(UserTenantResolver::class),
                'jwt_claim' => service(JwtClaimTenantResolver::class),
                'header' => service(HeaderTenantResolver::class),
                'subdomain' => service(SubdomainTenantResolver::class),
                default => throw new \InvalidArgumentException(sprintf('Unknown tenant resolution strategy "%s".', $strategy)),
            };
        }

        $services->set(CompositeTenantResolver::class)
            ->arg('$resolvers', $resolverRefs);
        $services->alias(TenantResolverInterface::class, CompositeTenantResolver::class);
    }

    /**
     * @param array{
     *     enabled: bool,
     *     isolation: string,
     *     tenant_connection: string,
     *     control_plane_connection: string,
     *     schema_prefix: string,
     *     base_schemas: list<string>,
     *     quotas_enabled: bool,
     *     resolution: list<string>,
     *     tenant_entity: string,
     *     unscoped_entities: list<string>,
     *     jwt_secret: string,
     *     jwt_id_claim: string,
     *     jwt_name_claim: string,
     *     tenant_header: string,
     *     base_domain: ?string,
     *     rls_enabled: bool,
     * } $config
     */
    private function registerCoreServices(array $config, ServicesConfigurator $services, ContainerBuilder $builder): void
    {
        $services->set(TenantRequestListener::class)
            ->arg('$isolation', $config['isolation'])
            ->arg('$rlsEnabled', $config['rls_enabled'])
            ->arg('$tenantEntityClass', $config['tenant_entity'])
            ->arg('$unscopedEntityClasses', $config['unscoped_entities']);

        $services->set(TenantStampListener::class)
            ->arg('$tenantEntityClass', $config['tenant_entity']);

        $services->set(DoctrineTenantRegistry::class)
            ->arg('$tenantEntityClass', $config['tenant_entity']);
        $services->alias(TenantRegistryInterface::class, DoctrineTenantRegistry::class);
        $services->alias(TenantDescriptorRegistryInterface::class, DoctrineTenantRegistry::class);

        $services->set(ColumnTenantConnectionSwitcher::class);

        if (in_array($config['isolation'], ['database', 'hybrid'], strict: true)) {
            $services->set(RegistryTenantDatabaseUrlProvider::class)
                ->arg('$controlPlaneEntityManager', service('doctrine.orm.' . $config['control_plane_connection'] . '_entity_manager'))
                ->arg('$tenantEntityClass', $config['tenant_entity']);
            $services->alias(TenantDatabaseUrlProviderInterface::class, RegistryTenantDatabaseUrlProvider::class);
            $services->alias(TenantIsolationTargetProviderInterface::class, RegistryTenantDatabaseUrlProvider::class);

            $services->set(DatabaseTenantConnectionSwitcher::class)
                ->arg('$tenantConnectionName', $config['tenant_connection']);
            $services->alias(TenantDatabaseConnectionSwitcherInterface::class, DatabaseTenantConnectionSwitcher::class);
        }

        if (in_array($config['isolation'], ['schema', 'hybrid'], strict: true)) {
            $services->set(PostgresSchemaTenantConnectionSwitcher::class)
                ->arg('$tenantConnectionName', $config['tenant_connection'])
                ->arg('$schemaPrefix', $config['schema_prefix'])
                ->arg('$baseSchemas', $config['base_schemas']);
            $services->alias(TenantSchemaConnectionSwitcherInterface::class, PostgresSchemaTenantConnectionSwitcher::class);
        }

        if (in_array($config['isolation'], ['schema', 'hybrid'], strict: true)) {
            $tenantRouter = $services->set(TenantRoutingConnectionSwitcher::class)
                ->arg('$isolation', $config['isolation'])
                ->arg('$tenantRegistry', service(TenantDescriptorRegistryInterface::class))
                ->arg('$schemaSwitcher', service(TenantSchemaConnectionSwitcherInterface::class));

            if ('hybrid' === $config['isolation']) {
                $tenantRouter
                    ->arg('$targetProvider', service(TenantIsolationTargetProviderInterface::class))
                    ->arg('$databaseSwitcher', service(TenantDatabaseConnectionSwitcherInterface::class))
                    ->arg('$columnSwitcher', service(ColumnTenantConnectionSwitcher::class));
            }

            $services->alias(TenantConnectionSwitcherInterface::class, TenantRoutingConnectionSwitcher::class);
        } else {
            $services->alias(
                TenantConnectionSwitcherInterface::class,
                'database' === $config['isolation'] ? DatabaseTenantConnectionSwitcher::class : ColumnTenantConnectionSwitcher::class,
            );
        }

        if ($config['quotas_enabled']) {
            $services->set(QuotaResourceRegistry::class);
            $services->set(CompositeQuotaUsageProvider::class)
                ->arg('$providers', tagged_iterator('nubit.quota_usage_provider'));
            $services->alias(QuotaUsageProviderInterface::class, CompositeQuotaUsageProvider::class);
            $services->set(FeatureQuotaEnforcer::class);
            $services->alias(QuotaEnforcerInterface::class, FeatureQuotaEnforcer::class);
            $services->set(QuotaEnforcementListener::class)
                ->arg('$enabled', true);
        }

        $services->set(TenantListCommand::class)
            ->tag('console.command');
    }
}
