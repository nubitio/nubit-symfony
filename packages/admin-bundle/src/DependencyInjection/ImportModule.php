<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use Nubit\AdminBundle\Import\ColumnMapper;
use Nubit\AdminBundle\Import\Controller\ImportController;
use Nubit\AdminBundle\Import\ImportableRegistry;
use Nubit\AdminBundle\Import\ImportFileStorage;
use Nubit\AdminBundle\Import\ImportRunner;
use Nubit\AdminBundle\Import\ImportService;
use Nubit\AdminBundle\Import\Reader\CsvRowReader;
use Nubit\AdminBundle\Import\Reader\RowReaderInterface;
use Nubit\AdminBundle\Import\Reader\XlsxRowReader;
use Nubit\AdminBundle\Import\ValueCoercer;
use Nubit\AdminBundle\OpenApi\ImportableDocumentationNormalizer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

final class ImportModule
{
    public const string READER_TAG = 'nubit.admin.import_reader';

    private function __construct() {}

    /**
     * @param array{
     *     enabled: bool,
     *     directory: string,
     *     default_currency: string,
     * } $config
     */
    public static function load(array $config, DefaultsConfigurator $services, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(RowReaderInterface::class)->addTag(self::READER_TAG);

        $services->set(CsvRowReader::class)->tag(self::READER_TAG, ['priority' => 0]);

        // XLSX support rides on phpoffice/phpspreadsheet, which is a `suggest`
        // for the export module. An application importing only CSV must not be
        // forced to install it.
        if (class_exists(IOFactory::class)) {
            $services->set(XlsxRowReader::class)->tag(self::READER_TAG, ['priority' => 10]);
        }

        $services->set(ImportableRegistry::class);
        $services->set(ColumnMapper::class);
        $services->set(ValueCoercer::class)->arg('$defaultCurrency', $config['default_currency']);
        $services->set(ImportFileStorage::class)->arg('$directory', $config['directory']);
        $services->set(ImportRunner::class);
        $services->set(ImportService::class)->arg('$readers', tagged_iterator(self::READER_TAG));

        $services->set(ImportController::class)->tag('controller.service_arguments');

        $services->set(ImportableDocumentationNormalizer::class)->decorate(
            'api_platform.hydra.normalizer.documentation',
            priority: -30,
        )->arg('$inner', service('.inner'));
    }
}
