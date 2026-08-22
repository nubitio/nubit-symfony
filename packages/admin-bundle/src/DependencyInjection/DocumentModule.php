<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Nubit\AdminBundle\Document\Controller\DocumentHistoryController;
use Nubit\AdminBundle\Document\Controller\DownloadDocumentController;
use Nubit\AdminBundle\Document\Controller\IssueDocumentController;
use Nubit\AdminBundle\Document\DocumentIssuer;
use Nubit\AdminBundle\Document\DocumentStorage;
use Nubit\AdminBundle\Document\Message\RenderDocumentHandler;
use Nubit\AdminBundle\Document\PrintableRegistry;
use Nubit\AdminBundle\Document\ResourceLocator;
use Nubit\AdminBundle\Document\WeasyPrintDocumentRenderer;
use Nubit\AdminBundle\OpenApi\PrintableDocumentationNormalizer;
use Nubit\ApiPlatform\Document\DocumentRendererInterface;
use Nubit\ApiPlatform\Document\DocumentTemplateInterface;
use Nubit\Platform\Export\PdfExporter;
use Nubit\Platform\Filesystem\FileManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

final class DocumentModule
{
    public const string TEMPLATE_TAG = 'nubit.admin.document_template';

    private function __construct() {}

    /**
     * @param array{
     *     enabled: bool,
     *     async: bool,
     *     directory: string,
     *     weasyprint_binary: string,
     *     storage: array{filesystem: ?string, local_directory: string},
     * } $config
     */
    public static function load(
        array $config,
        ContainerConfigurator $container,
        DefaultsConfigurator $services,
        ContainerBuilder $builder,
    ): void {
        // Templates are ordinary services found by class name, so the
        // #[Printable] attribute can name one and the issuer can fetch it
        // without the application registering anything.
        $builder->registerForAutoconfiguration(DocumentTemplateInterface::class)->addTag(self::TEMPLATE_TAG);

        if (null !== $config['storage']['filesystem']) {
            $services->alias('nubit_admin.document.filesystem', $config['storage']['filesystem']);
        } else {
            $services->set('nubit_admin.document.local_adapter', LocalFilesystemAdapter::class)->arg(
                '$location',
                $config['storage']['local_directory'],
            );
            $services->set('nubit_admin.document.filesystem', Filesystem::class)->arg(
                '$adapter',
                service('nubit_admin.document.local_adapter'),
            );
        }

        $services->set('nubit_admin.document.file_manager', FileManager::class)->arg(
            '$defaultFilesystem',
            service('nubit_admin.document.filesystem'),
        );

        $services->set(DocumentStorage::class)->arg('$fileManager', service('nubit_admin.document.file_manager'))->arg(
            '$directory',
            $config['directory'],
        );

        $services->set(PrintableRegistry::class);
        $services->set(ResourceLocator::class);

        $services->set('nubit_admin.document.pdf_exporter', PdfExporter::class)->arg(
            '$weasyprintBinary',
            $config['weasyprint_binary'],
        );

        // Aliased, not hard-wired: an application rendering through Gotenberg or
        // a headless browser replaces this one service and keeps every issuing
        // rule intact.
        $services->set(WeasyPrintDocumentRenderer::class)->arg(
            '$pdfExporter',
            service('nubit_admin.document.pdf_exporter'),
        );
        $services->alias(DocumentRendererInterface::class, WeasyPrintDocumentRenderer::class);

        $services->set(DocumentIssuer::class)->arg('$templates', tagged_locator(self::TEMPLATE_TAG))->arg(
            '$async',
            $config['async'],
        );

        $services->set(RenderDocumentHandler::class);

        $services->set(IssueDocumentController::class)->tag('controller.service_arguments');
        $services->set(DownloadDocumentController::class)->tag('controller.service_arguments');
        $services->set(DocumentHistoryController::class)->tag('controller.service_arguments');

        // Publishing the capability is what makes the print button appear
        // without the frontend being told about it separately.
        $services->set(PrintableDocumentationNormalizer::class)->decorate(
            'api_platform.hydra.normalizer.documentation',
            priority: -20,
        )->arg('$inner', service('.inner'));
    }
}
