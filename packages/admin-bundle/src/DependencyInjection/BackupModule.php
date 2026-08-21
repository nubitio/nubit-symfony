<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Nubit\AdminBundle\Command\TenantBackupCommand;
use Nubit\Platform\Filesystem\FileManager;
use Nubit\Platform\Tenant\Backup\PostgresTenantBackupRunner;
use Nubit\Platform\Tenant\Contract\TenantBackupRunnerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class BackupModule
{
    private function __construct()
    {
    }

    /**
     * @param array{
     *     enabled: bool,
     *     storage: array{filesystem: ?string, local_directory: string},
     *     pg_dump_binary: string,
     *     timeout_seconds: int,
     * } $config
     */
    public static function load(array $config, DefaultsConfigurator $services): void
    {
        if (null !== $config['storage']['filesystem']) {
            $services->alias('nubit_admin.backup.filesystem', $config['storage']['filesystem']);
        } else {
            $services->set('nubit_admin.backup.local_adapter', LocalFilesystemAdapter::class)
                ->arg('$location', $config['storage']['local_directory']);
            $services->set('nubit_admin.backup.filesystem', Filesystem::class)
                ->arg('$adapter', service('nubit_admin.backup.local_adapter'));
        }

        $services->set('nubit_admin.backup.file_manager', FileManager::class)
            ->arg('$defaultFilesystem', service('nubit_admin.backup.filesystem'));

        $services->set(PostgresTenantBackupRunner::class)
            ->arg('$fileManager', service('nubit_admin.backup.file_manager'))
            ->arg('$pgDumpBinary', $config['pg_dump_binary'])
            ->arg('$timeoutSeconds', $config['timeout_seconds']);
        $services->alias(TenantBackupRunnerInterface::class, PostgresTenantBackupRunner::class);

        $services->set(TenantBackupCommand::class);
    }
}
