<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use LogicException;
use Nubit\AdminBundle\Export\Controller\ExportJobController;
use Nubit\AdminBundle\Export\EventListener\ExportContentDispositionListener;
use Nubit\AdminBundle\Export\ExportableResourceMetadataFactory;
use Nubit\AdminBundle\Export\ExportFileStorage;
use Nubit\AdminBundle\Export\ExportRequestService;
use Nubit\AdminBundle\Export\ExportRowMapper;
use Nubit\AdminBundle\Export\Message\RunExportHandler;
use Nubit\AdminBundle\Export\QueuedExportRunner;
use Nubit\AdminBundle\Export\Writer\CsvExportWriter;
use Nubit\AdminBundle\Export\Writer\QueuedExportWriterInterface;
use Nubit\AdminBundle\Export\Writer\XlsxExportWriter;
use Nubit\AdminBundle\Export\XlsxEncoder;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class ExportModule
{
    private function __construct() {}

    /**
     * @param array{
     *     enabled: bool,
     *     queued: bool,
     *     directory: string,
     *     inline_limit: int,
     *     queued_format: string,
     * } $config
     */
    public static function load(array $config, DefaultsConfigurator $services): void
    {
        // Fail at container build with a name the reader can act on, rather
        // than at the first ?_format=xlsx request with a "class not found".
        if (!class_exists(Spreadsheet::class)) {
            throw new LogicException(
                'nubit_admin.export.enabled requires phpoffice/phpspreadsheet (and ext-zip). '
                . 'Run: composer require phpoffice/phpspreadsheet',
            );
        }

        $services->set(XlsxEncoder::class)->tag('serializer.encoder');
        $services->set(ExportContentDispositionListener::class);

        // Registering the format above turns it on for every resource, so this
        // decorator takes it back off everywhere #[Exportable] is absent.
        // Decorating the *attribute* factory keeps the restriction in the same
        // layer that reads the resource's own attributes.
        $services->set(ExportableResourceMetadataFactory::class)->decorate(
            'api_platform.metadata.resource.metadata_collection_factory.attributes',
        )->arg('$decorated', service('.inner'))->arg('$formats', '%api_platform.formats%');

        if (!$config['queued']) {
            return;
        }

        // XLSX by default, streamed. PhpSpreadsheet — which the inline export
        // uses for its styling, totals and validation — builds the entire
        // workbook in memory first, so it cannot be the queued writer: a
        // half-million-row sheet is the out-of-memory failure queueing exists to
        // avoid. OpenSpout appends each row to the sheet as it arrives.
        if ('xlsx' === $config['queued_format']) {
            if (!class_exists(XlsxWriter::class)) {
                throw new LogicException(
                    'nubit_admin.export.queued_format: xlsx needs openspout/openspout, the only PHP writer that '
                    . 'streams XLSX. Run: composer require openspout/openspout — or set queued_format: csv, '
                    . 'which needs nothing.',
                );
            }

            $services->set(XlsxExportWriter::class);
            $services->alias(QueuedExportWriterInterface::class, XlsxExportWriter::class);
        } else {
            $services->set(CsvExportWriter::class);
            $services->alias(QueuedExportWriterInterface::class, CsvExportWriter::class);
        }

        $services->set(ExportFileStorage::class)->arg('$directory', $config['directory']);
        $services->set(ExportRowMapper::class);
        $services->set(QueuedExportRunner::class);
        $services->set(RunExportHandler::class);
        $services->set(ExportRequestService::class)->arg('$defaultInlineLimit', $config['inline_limit']);
        $services->set(ExportJobController::class)->tag('controller.service_arguments');
    }
}
