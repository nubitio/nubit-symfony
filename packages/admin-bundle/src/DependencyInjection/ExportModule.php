<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use LogicException;
use Nubit\AdminBundle\Export\EventListener\ExportContentDispositionListener;
use Nubit\AdminBundle\Export\ExportableResourceMetadataFactory;
use Nubit\AdminBundle\Export\XlsxEncoder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class ExportModule
{
    private function __construct() {}

    public static function load(DefaultsConfigurator $services): void
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
    }
}
