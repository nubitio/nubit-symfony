<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use Nubit\AdminBundle\Audit\AuditTrailListener;
use Nubit\AdminBundle\Audit\Controller\AuditTrailController;
use Nubit\AdminBundle\Command\PurgeAuditLogCommand;
use Nubit\AdminBundle\Security\RouteGuard;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

final class AuditModule
{
    private function __construct() {}

    /**
     * @param array{
     *     enabled: bool,
     *     ignored_fields: list<string>,
     *     purge_retention_days: int,
     *     read_security: ?string,
     * } $config
     */
    public static function load(array $config, DefaultsConfigurator $services): void
    {
        RouteGuard::assertAvailable($config['read_security'], 'nubit_admin.audit.read_security');

        $services->set(AuditTrailListener::class)->arg(
            '$ignoredFields',
            $config['ignored_fields'],
        )->tag('doctrine.event_listener', ['event' => 'onFlush'])->tag('doctrine.event_listener', [
            'event' => 'postFlush',
        ]);

        $services->set(AuditTrailController::class)->arg('$routeSecurity', $config['read_security'])->tag(
            'controller.service_arguments',
        );

        $services->set(PurgeAuditLogCommand::class)->arg('$retentionDays', $config['purge_retention_days']);
    }

    /**
     * Mapping only. AuditLog is not an ApiResource (a plain route serves it
     * instead), so only the Doctrine mapping is needed.
     */
    public static function prepend(ContainerBuilder $container): void
    {
        if (!BundleConfig::isFeatureEnabled($container, 'audit')) {
            return;
        }

        BundleConfig::mapEntities(
            $container,
            'NubitAdminAuditBundle',
            __DIR__ . '/../Audit/Entity',
            'Nubit\\AdminBundle\\Audit\\Entity',
        );
    }
}
