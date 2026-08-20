<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use Nubit\AdminBundle\Analytics\DeliverAnalyticsOutboxHandler;
use Nubit\AdminBundle\Analytics\DoctrineOutboxAnalyticsProvider;
use Nubit\AdminBundle\Analytics\UnavailableAnalyticsDeliveryProvider;
use Nubit\AdminBundle\Command\DispatchAnalyticsOutboxCommand;
use Nubit\AdminBundle\Command\PurgeAnalyticsOutboxCommand;
use Nubit\Platform\Analytics\AnalyticsPublisher;
use Nubit\Platform\Analytics\Contract\AnalyticsConsentCheckerInterface;
use Nubit\Platform\Analytics\Contract\AnalyticsDeduplicatorInterface;
use Nubit\Platform\Analytics\Contract\AnalyticsDeliveryProviderInterface;
use Nubit\Platform\Analytics\Contract\AnalyticsProviderInterface;
use Nubit\Platform\Analytics\InMemoryAnalyticsDeduplicator;
use Nubit\Platform\Analytics\OperationalOnlyConsentChecker;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Privacy\Metadata\SensitiveDataMetadataReader;
use Nubit\Platform\Privacy\Policy\DefaultSensitiveDataPolicy;
use Nubit\Platform\Privacy\Policy\SensitiveDataPolicyInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class AnalyticsModule
{
    /** @param array{enabled: bool, redaction_hmac_key: string, deduplication_capacity: int, batch_size: int, maximum_retry_delay: int, retention_days: int} $config */
    public static function load(array $config, DefaultsConfigurator $services): void
    {
        $services->set(SensitiveDataMetadataReader::class);
        $services->set(DefaultSensitiveDataPolicy::class);
        $services->alias(SensitiveDataPolicyInterface::class, DefaultSensitiveDataPolicy::class);
        $services->set('nubit.analytics.redactor', DataRedactor::class)->arg(
            '$metadataReader',
            service(SensitiveDataMetadataReader::class),
        )->arg('$policy', service(SensitiveDataPolicyInterface::class))->arg('$hmacKey', $config['redaction_hmac_key']);

        $services->set(OperationalOnlyConsentChecker::class);
        $services->alias(AnalyticsConsentCheckerInterface::class, OperationalOnlyConsentChecker::class);
        $services->set(InMemoryAnalyticsDeduplicator::class)->arg('$capacity', $config['deduplication_capacity']);
        $services->alias(AnalyticsDeduplicatorInterface::class, InMemoryAnalyticsDeduplicator::class);
        $services->set(DoctrineOutboxAnalyticsProvider::class);
        $services->alias(AnalyticsProviderInterface::class, DoctrineOutboxAnalyticsProvider::class);
        $services->set(AnalyticsPublisher::class)->arg('$redactor', service('nubit.analytics.redactor'));

        $services->set(UnavailableAnalyticsDeliveryProvider::class);
        $services->alias(AnalyticsDeliveryProviderInterface::class, UnavailableAnalyticsDeliveryProvider::class);
        $services->set(DeliverAnalyticsOutboxHandler::class)->arg(
            '$maximumDelaySeconds',
            $config['maximum_retry_delay'],
        );
        $services->set(DispatchAnalyticsOutboxCommand::class)->arg('$batchSize', $config['batch_size']);
        $services->set(PurgeAnalyticsOutboxCommand::class)->arg('$retentionDays', $config['retention_days']);
    }
}
