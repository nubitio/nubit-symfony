<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection\Compiler;

use Nubit\ApiPlatform\Doctrine\ApproximateCounter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Points ApproximateCounter at whichever DBAL connection the application
 * actually named its default.
 *
 * DoctrineBundle never registers a service literally called
 * "doctrine.dbal.default_connection" — it registers "doctrine.dbal.<name>_connection"
 * for each configured connection, "<name>" being "default" only for applications
 * that never named theirs. A multi-connection app (tenant + control-plane, say)
 * names its default something else, and the container fails to compile.
 *
 * The name is only known once DoctrineExtension has run, which is what a
 * compiler pass is for — loadExtension() has no such guarantee about ordering
 * relative to other bundles.
 */
final class ResolveApproximateCounterConnectionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ApproximateCounter::class)) {
            return;
        }

        $connectionName = $container->hasParameter('doctrine.default_connection')
            ? $container->getParameter('doctrine.default_connection')
            : 'default';

        $container->getDefinition(ApproximateCounter::class)->setArgument(
            '$connection',
            new Reference(\sprintf('doctrine.dbal.%s_connection', $connectionName)),
        );
    }
}
