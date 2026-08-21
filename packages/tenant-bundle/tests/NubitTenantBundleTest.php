<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests;

use Nubit\TenantBundle\Doctrine\Filter\TenantFilter;
use Nubit\TenantBundle\Entity\Tenant;
use Nubit\TenantBundle\NubitTenantBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Doctrine's `auto_mapping` maps every registered bundle, so the Tenant entity
 * shipped here reaches the schema even in applications that named their own
 * tenant root. The prepend below is what keeps an unused nubit_tenant table out
 * of their migrations.
 */
final class NubitTenantBundleTest extends TestCase
{
    public function testTheBundleEntityIsUnmappedWhenTheApplicationNamesItsOwnTenantRoot(): void
    {
        $orm = self::prependedOrmConfig(['enabled' => true, 'tenant_entity' => 'App\\Entity\\Organization']);

        self::assertSame(['NubitTenantBundle' => false], $orm['mappings'] ?? null);
    }

    public function testTheBundleEntityStaysMappedWhenItIsTheTenantRoot(): void
    {
        $orm = self::prependedOrmConfig(['enabled' => true, 'tenant_entity' => Tenant::class]);

        self::assertArrayNotHasKey('mappings', $orm);
    }

    /** No `tenant_entity` means the bundle's own entity, which must stay mapped. */
    public function testTheBundleEntityStaysMappedWhenNoTenantRootIsConfigured(): void
    {
        $orm = self::prependedOrmConfig(['enabled' => true]);

        self::assertArrayNotHasKey('mappings', $orm);
    }

    public function testTheTenantFilterIsRegisteredDisabledSoRequestsEnableItPerScope(): void
    {
        $orm = self::prependedOrmConfig(['enabled' => true, 'tenant_entity' => 'App\\Entity\\Organization']);

        self::assertSame(
            ['class' => TenantFilter::class, 'enabled' => false],
            $orm['filters'][TenantFilter::NAME] ?? null,
        );
    }

    /**
     * A disabled bundle still has to unmap its entity: it stays installed
     * because admin-bundle depends on it, and auto_mapping does not care
     * whether it is switched on.
     */
    public function testADisabledBundleUnmapsItsEntityAndRegistersNoFilter(): void
    {
        $orm = self::prependedOrmConfig(['enabled' => false]);

        self::assertSame(['NubitTenantBundle' => false], $orm['mappings'] ?? null);
        self::assertArrayNotHasKey('filters', $orm);
    }

    public function testADisabledBundleUnmapsItsEntityEvenWithNoConfigurationAtAll(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(self::extension('nubit_tenant'));
        $container->registerExtension(self::extension('doctrine'));

        (new NubitTenantBundle())->prependExtension(self::configurator(), $container);

        $prepended = $container->getExtensionConfig('doctrine');
        self::assertCount(1, $prepended);
        self::assertSame(['NubitTenantBundle' => false], $prepended[0]['orm']['mappings'] ?? null);
    }

    // ── harness ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function prependedOrmConfig(array $config): array
    {
        $container = self::containerWith($config);
        (new NubitTenantBundle())->prependExtension(self::configurator(), $container);

        // The bundle may prepend more than once; the container merges them, so
        // the assertions read the combined `orm` section.
        $orm = [];
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            self::assertIsArray($config['orm'] ?? null);
            $orm = array_merge($orm, $config['orm']);
        }

        return $orm;
    }

    /** @param array<string, mixed> $config */
    private static function containerWith(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(self::extension('nubit_tenant'));
        $container->registerExtension(self::extension('doctrine'));
        $container->prependExtensionConfig('nubit_tenant', $config);

        return $container;
    }

    private static function extension(string $alias): ExtensionInterface
    {
        return new class($alias) implements ExtensionInterface {
            public function __construct(
                private readonly string $alias,
            ) {}

            public function getAlias(): string
            {
                return $this->alias;
            }

            public function load(array $configs, ContainerBuilder $container): void {}

            public function getNamespace(): string
            {
                return '';
            }

            public function getXsdValidationBasePath(): string|false
            {
                return false;
            }
        };
    }

    /** prependExtension() never touches the configurator; this only satisfies the signature. */
    private static function configurator(): ContainerConfigurator
    {
        $container = new ContainerBuilder();
        $instanceof = [];

        return new ContainerConfigurator(
            $container,
            new PhpFileLoader($container, new FileLocator()),
            $instanceof,
            __FILE__,
            __FILE__,
        );
    }
}
