<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\ApiPlatform\Document\DocumentRendererInterface;
use Nubit\Tests\Integration\Fixture\Controller\TestQueryController;
use Nubit\Tests\Integration\Fixture\Document\InvoiceTemplate;
use Nubit\Tests\Integration\Fixture\Document\RecordingRenderer;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Bootable kernel for integration tests.
 *
 * Each test declares the bundles it wants, the extension configuration to apply
 * and the fixture entities to map. The container compiles for real — that is
 * the point: a unit test asserts what a bundle *intends* to register, only a
 * booted kernel proves the wiring holds.
 *
 * Kernels are cached per configuration digest, so a suite reusing the same
 * shape pays compilation once.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param list<class-string<BundleInterface>>              $extraBundles
     * @param array<string, array<string, mixed>>              $extensionConfig
     * @param array<string, array{dir: string, prefix: string}> $entityMappings
     */
    public function __construct(
        private readonly array $extraBundles = [],
        private readonly array $extensionConfig = [],
        private readonly array $entityMappings = [],
        private readonly string $databaseUrl = '',
        /**
         * Security is a whole test subject of its own, so it is replaced rather
         * than merged: an authentication suite needs a firewall that actually
         * authenticates, and merging that into the open default would produce a
         * configuration neither test asked for.
         *
         * @var array<string, mixed>|null
         */
        private readonly ?array $securityConfig = null,
    ) {
        // Debug off: the Symfony error handler it installs never gets torn down
        // between tests, and PHPUnit rightly reports that as leaked global
        // state. Nothing here asserts on debug-only behaviour.
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        // NubitAdminBundle decorates api_platform.hydra.normalizer.documentation
        // unconditionally, so it cannot compile without this bundle registered.
        // That matches how it is installed in practice, and the DI matrix test
        // pins the constraint so it stays a deliberate dependency.
        yield new ApiPlatformBundle();

        foreach ($this->extraBundles as $bundle) {
            yield new $bundle();
        }
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nubit-integration/' . $this->digest() . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nubit-integration/' . $this->digest() . '/log';
    }

    protected function configureContainer(
        ContainerConfigurator $configurator,
        LoaderInterface $loader,
        ContainerBuilder $container,
    ): void {
        $configurator->extension('framework', [
            'test' => true,
            'secret' => 'nubit-integration-secret-key-at-least-32-bytes',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'validation' => ['enabled' => true],
            'property_access' => true,
        ]);

        // SecurityBundle must declare a firewall for the Security service the
        // tenant listener depends on to exist. Isolation tests are not about
        // authentication, so their firewall stays open.
        $configurator->extension(
            'security',
            $this->securityConfig ?? [
                'providers' => ['in_memory' => ['memory' => null]],
                'firewalls' => ['main' => ['security' => false]],
            ],
        );

        $configurator->extension('doctrine', [
            'dbal' => [
                'url' => $this->databaseUrl,
                'use_savepoints' => true,
                'profiling' => false,
            ],
            'orm' => [
                'auto_generate_proxy_classes' => true,
                // Native lazy objects from PHP 8.4 on; the CI matrix still
                // includes 8.3, where Doctrine falls back to lazy ghosts.
                'enable_native_lazy_objects' => \PHP_VERSION_ID >= 80400,
                'enable_lazy_ghost_objects' => true,
                'report_fields_where_declared' => true,
                'validate_xml_mapping' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                // Applications run with auto_mapping on, and the bundles rely on
                // it: tenant-bundle expresses "do not map my Tenant entity" by
                // prepending a `false` mapping rather than by staying silent.
                // Turning it off here would quietly skip that logic.
                'auto_mapping' => true,
                'mappings' => $this->doctrineMappings(),
            ],
        ]);

        $configurator->extension('api_platform', [
            'title' => 'Nubit integration',
            'version' => '1.0.0',
            'mapping' => ['paths' => [__DIR__ . '/Fixture/Entity']],
            'formats' => ['jsonld' => ['mime_types' => ['application/ld+json']]],
            'docs_formats' => ['jsonld' => ['mime_types' => ['application/ld+json']]],
            'defaults' => ['pagination_client_items_per_page' => true],
        ]);

        foreach ($this->extensionConfig as $extension => $config) {
            $configurator->extension($extension, $config);
        }

        $services = $configurator->services();

        $services->set(TestQueryController::class)->autowire()->public()->tag('controller.service_arguments');

        // Document fixtures. Registered unconditionally — unused private
        // services are pruned — and the renderer deliberately replaces the
        // bundled WeasyPrint one: the issuing rules under test are independent
        // of the PDF engine, and substituting it keeps the suite runnable
        // without a Python toolchain in the image.
        $services->set(InvoiceTemplate::class)->autowire()->public()->tag('nubit.admin.document_template');
        $services->set(RecordingRenderer::class)->public();
        $services->alias(DocumentRendererInterface::class, RecordingRenderer::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // API Platform contributes its resource routes through its own loader,
        // under the same /api prefix an application's
        // config/routes/api_platform.yaml applies.
        $routes->import('.', 'api_platform')->prefix('/api');

        // The bundle ships its routes as a file applications import; importing
        // it here is what makes /api/auth/login and /api/me exist at all.
        if (in_array(NubitAdminBundle::class, $this->extraBundles, true)) {
            $routes->import(
                dirname((new \ReflectionClass(NubitAdminBundle::class))->getFileName() ?: '', 2) . '/config/routes.php',
            );
        }

        $routes->add('nubit_test_list', '/_test/list')->controller([TestQueryController::class, 'list']);
        $routes->add('nubit_test_find', '/_test/find')->controller([TestQueryController::class, 'find']);
        $routes->add('nubit_test_join', '/_test/join')->controller([TestQueryController::class, 'join']);
        $routes->add('nubit_test_dql', '/_test/dql')->controller([TestQueryController::class, 'dql']);
    }

    /** @return array<string, array<string, mixed>> */
    private function doctrineMappings(): array
    {
        $mappings = [];

        foreach ($this->entityMappings as $name => $mapping) {
            $mappings[$name] = [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => $mapping['dir'],
                'prefix' => $mapping['prefix'],
                'alias' => $name,
            ];
        }

        return $mappings;
    }

    /**
     * Distinct configurations must not share a compiled container, and an
     * identical configuration must not recompile on every test.
     */
    private function digest(): string
    {
        return substr(
            md5(serialize([
                $this->extraBundles,
                $this->extensionConfig,
                $this->entityMappings,
                $this->databaseUrl,
            ])),
            0,
            12,
        );
    }
}
