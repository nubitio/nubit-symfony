<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use Nubit\AdminBundle\Controller\RuntimeConfigController;
use Nubit\AdminBundle\Runtime\NullRuntimeConfigProvider;
use Nubit\AdminBundle\Runtime\RuntimeConfigProviderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

final class RuntimeConfigModule
{
    private function __construct() {}

    public static function load(bool $enabled, ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $container->parameters()->set('nubit_admin.runtime_config.enabled', $enabled);

        $services->set(NullRuntimeConfigProvider::class);
        $services->alias(RuntimeConfigProviderInterface::class, NullRuntimeConfigProvider::class);

        $services->set(RuntimeConfigController::class)->arg('$enabled', $enabled)->tag('controller.service_arguments');
    }
}
