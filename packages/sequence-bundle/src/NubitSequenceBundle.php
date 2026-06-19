<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle;

use Nubit\SequenceBundle\EventListener\SequenceStampListener;
use Nubit\SequenceBundle\OpenApi\SequenceDocumentationNormalizer;
use Nubit\SequenceBundle\Sequence\SequenceAllocator;
use Nubit\SequenceBundle\Sequence\SequenceMetadata;
use Nubit\SequenceBundle\Sequence\SequenceRegistry;
use Nubit\SequenceBundle\Sequence\SequenceScopeResolver;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class NubitSequenceBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'NubitSequenceBundle' => [
                        'type' => 'attribute',
                        'is_bundle' => true,
                        'dir' => 'src/Entity',
                        'prefix' => 'Nubit\\SequenceBundle\\Entity',
                        'alias' => 'NubitSequenceBundle',
                    ],
                ],
            ],
        ]);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Enable automatic sequence allocation on prePersist and x-sequence OpenAPI hints.')
                    ->defaultTrue()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set('nubit_sequence.enabled', $config['enabled']);

        if (!$config['enabled']) {
            return;
        }

        $services = $container->services();
        $services->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(SequenceMetadata::class);
        $services->set(SequenceScopeResolver::class);
        $services->set(SequenceRegistry::class);
        $services->set(SequenceAllocator::class);
        $services->set(SequenceStampListener::class);

        $services->set(SequenceDocumentationNormalizer::class)
            ->decorate('Nubit\ApiPlatform\OpenApi\TranslatedDocumentationNormalizer')
            ->args([
                '$inner' => service('.inner'),
            ]);
    }
}