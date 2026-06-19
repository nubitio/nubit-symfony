<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle;

use Nubit\WorkflowBundle\Controller\WorkflowTransitionController;
use Nubit\WorkflowBundle\OpenApi\WorkflowDocumentationNormalizer;
use Nubit\WorkflowBundle\Routing\WorkflowRouteLoader;
use Nubit\WorkflowBundle\Workflow\WorkflowEngine;
use Nubit\WorkflowBundle\Workflow\WorkflowMetadata;
use Nubit\WorkflowBundle\Workflow\WorkflowRegistry;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

final class NubitWorkflowBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Enable workflow transition routes and x-workflow OpenAPI hints.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('api_route_prefix')
                    ->info('Global API route prefix prepended when inferring collection paths.')
                    ->defaultValue('/api')
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set('nubit_workflow.enabled', $config['enabled']);

        if (!$config['enabled']) {
            return;
        }

        $services = $container->services();
        $services->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(WorkflowMetadata::class);
        $services->set(WorkflowRegistry::class)
            ->arg('$apiRoutePrefix', $config['api_route_prefix']);
        $services->set(WorkflowEngine::class)
            ->arg('$guardLocator', tagged_locator('nubit.workflow_guard', indexAttribute: 'class'));
        $services->set(WorkflowTransitionController::class);
        $services->set(WorkflowRouteLoader::class)
            ->tag('routing.loader', ['type' => 'nubit_workflow']);

        $services->set(WorkflowDocumentationNormalizer::class)
            ->decorate('Nubit\ApiPlatform\OpenApi\TranslatedDocumentationNormalizer')
            ->args([
                '$inner' => service('.inner'),
            ]);
    }
}