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

    public function testADisabledBundlePrependsNothing(): void
    {
        $container = self::containerWith(['enabled' => false]);

        (new NubitTenantBundle())->prependExtension(self::configurator(), $container);

        self::assertSame([['enabled' => false]], $container->getExtensionConfig('nubit_tenant'));
        self::assertSame([], $container->getExtensionConfig('doctrine'));
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

        $prepended = $container->getExtensionConfig('doctrine');
        self::assertCount(1, $prepended);
        self::assertIsArray($prepended[0]['orm'] ?? null);

        return $prepended[0]['orm'];
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
