<?php

declare(strict_types=1);

namespace Nubit\TenantBundle;

use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantDescriptorRegistryInterface;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Nubit\TenantBundle\Command\TenantListCommand;
use Nubit\TenantBundle\Doctrine\Filter\TenantFilter;
use Nubit\TenantBundle\Entity\Tenant;
use Nubit\TenantBundle\EventListener\TenantRequestListener;
use Nubit\TenantBundle\EventListener\TenantStampListener;
use Nubit\TenantBundle\Registry\DoctrineTenantRegistry;
use Nubit\TenantBundle\Resolver\CompositeTenantResolver;
use Nubit\TenantBundle\Resolver\HeaderTenantResolver;
use Nubit\TenantBundle\Resolver\JwtClaimTenantResolver;
use Nubit\TenantBundle\Resolver\SubdomainTenantResolver;
use Nubit\TenantBundle\Resolver\TenantResolverInterface;
use Nubit\TenantBundle\Resolver\UserTenantResolver;
use Nubit\TenantBundle\Switcher\ColumnTenantConnectionSwitcher;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
                    ->values(['column'])
                    ->defaultValue('column')
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

        $this->registerResolvers($config, $services);
        $this->registerCoreServices($config, $services);
    }

    /**
     * @param array{
     *     enabled: bool,
     *     isolation: string,
     *     resolution: list<string>,
     *     tenant_entity: string,
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
     *     resolution: list<string>,
     *     tenant_entity: string,
     *     jwt_secret: string,
     *     jwt_id_claim: string,
     *     jwt_name_claim: string,
     *     tenant_header: string,
     *     base_domain: ?string,
     *     rls_enabled: bool,
     * } $config
     */
    private function registerCoreServices(array $config, ServicesConfigurator $services): void
    {
        $services->set(TenantRequestListener::class)
            ->arg('$rlsEnabled', $config['rls_enabled'])
            ->arg('$tenantEntityClass', $config['tenant_entity']);

        $services->set(TenantStampListener::class)
            ->arg('$tenantEntityClass', $config['tenant_entity']);

        $services->set(DoctrineTenantRegistry::class)
            ->arg('$tenantEntityClass', $config['tenant_entity']);
        $services->alias(TenantRegistryInterface::class, DoctrineTenantRegistry::class);
        $services->alias(TenantDescriptorRegistryInterface::class, DoctrineTenantRegistry::class);

        $services->set(ColumnTenantConnectionSwitcher::class);
        $services->alias(TenantConnectionSwitcherInterface::class, ColumnTenantConnectionSwitcher::class);

        $services->set(TenantListCommand::class)
            ->tag('console.command');
    }
}