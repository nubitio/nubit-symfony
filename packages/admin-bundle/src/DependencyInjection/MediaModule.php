<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Nubit\AdminBundle\Command\PurgeMediaCommand;
use Nubit\AdminBundle\Media\Controller\MediaFileController;
use Nubit\AdminBundle\Media\Controller\MediaUploadController;
use Nubit\AdminBundle\Media\MediaStorage;
use Nubit\AdminBundle\Media\MediaUrlResolverInterface;
use Nubit\AdminBundle\Media\RouteMediaUrlResolver;
use Nubit\AdminBundle\Media\Serializer\MediaNormalizer;
use Nubit\AdminBundle\Media\State\MediaSoftDeleteProcessor;
use Nubit\Platform\Filesystem\FileManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class MediaModule
{
    private function __construct()
    {
    }

    /**
     * @param array{
     *     storage: array{filesystem: ?string, local_directory: string},
     *     directory: string,
     *     purge_retention_days: int,
     *     max_size: int,
     *     allowed_mimes: list<string>,
     * } $config
     */
    public static function load(
        array $config,
        ContainerConfigurator $container,
        DefaultsConfigurator $services,
    ): void {
        $container->parameters()->set('nubit_admin.media.directory', $config['directory']);

        if (null !== $config['storage']['filesystem']) {
            $services->alias('nubit_admin.media.filesystem', $config['storage']['filesystem']);
        } else {
            $services->set('nubit_admin.media.local_adapter', LocalFilesystemAdapter::class)
                ->arg('$location', $config['storage']['local_directory']);
            $services->set('nubit_admin.media.filesystem', Filesystem::class)
                ->arg('$adapter', service('nubit_admin.media.local_adapter'));
        }

        $services->set('nubit_admin.media.file_manager', FileManager::class)
            ->arg('$defaultFilesystem', service('nubit_admin.media.filesystem'));

        $services->set(MediaStorage::class)
            ->arg('$fileManager', service('nubit_admin.media.file_manager'))
            ->arg('$directory', $config['directory'])
            ->arg('$allowedMimes', $config['allowed_mimes'])
            ->arg('$maxSize', $config['max_size']);

        $services->set(RouteMediaUrlResolver::class);
        $services->alias(MediaUrlResolverInterface::class, RouteMediaUrlResolver::class);
        $services->set(MediaNormalizer::class);
        $services->set(MediaSoftDeleteProcessor::class);
        $services->set(MediaUploadController::class)->tag('controller.service_arguments');
        $services->set(MediaFileController::class)->tag('controller.service_arguments');
        $services->set(PurgeMediaCommand::class)
            ->arg('$retentionDays', $config['purge_retention_days']);
    }
}
