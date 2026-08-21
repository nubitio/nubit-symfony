<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Tests;

use Nubit\SequenceBundle\NubitSequenceBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * The entity mapping follows the `enabled` flag. Registering it unconditionally
 * left every application — including ones that never allocate a sequence —
 * carrying an empty nubit_sequence_counter table in its schema.
 */
final class NubitSequenceBundleTest extends TestCase
{
    public function testTheEntityIsMappedWhileTheBundleIsEnabled(): void
    {
        $mappings = self::prependedMappings(['enabled' => true]);

        self::assertSame('Nubit\\SequenceBundle\\Entity', $mappings['NubitSequenceBundle']['prefix'] ?? null);
    }

    /** The node defaults to true, so no configuration at all still maps it. */
    public function testTheEntityIsMappedWhenNothingIsConfigured(): void
    {
        $mappings = self::prependedMappings([]);

        self::assertArrayHasKey('NubitSequenceBundle', $mappings);
    }

    public function testADisabledBundleMapsNothing(): void
    {
        $container = self::containerWith(['enabled' => false]);

        (new NubitSequenceBundle())->prependExtension(self::configurator(), $container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    // ── harness ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function prependedMappings(array $config): array
    {
        $container = self::containerWith($config);
        (new NubitSequenceBundle())->prependExtension(self::configurator(), $container);

        $prepended = $container->getExtensionConfig('doctrine');
        self::assertCount(1, $prepended);
        self::assertIsArray($prepended[0]['orm']['mappings'] ?? null);

        return $prepended[0]['orm']['mappings'];
    }

    /** @param array<string, mixed> $config */
    private static function containerWith(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(self::extension('nubit_sequence'));
        $container->registerExtension(self::extension('doctrine'));
        if ([] !== $config) {
            $container->prependExtensionConfig('nubit_sequence', $config);
        }

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
