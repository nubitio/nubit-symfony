<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use LogicException;
use Monolog\LogRecord;
use Nubit\Platform\Observability\Logging\SensitiveDataProcessor;
use Nubit\Platform\Observability\Logging\TenantLogProcessor;
use Nubit\Platform\Observability\Tracing\HttpRequestTracingListener;
use Nubit\Platform\Observability\Tracing\TenantTracer;
use Nubit\Platform\Observability\Tracing\TenantTracerFactory;
use Nubit\Platform\Observability\Tracing\TraceAttributeSanitizer;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Privacy\Metadata\SensitiveDataMetadataReader;
use Nubit\Platform\Privacy\Policy\DefaultSensitiveDataPolicy;
use Nubit\Platform\Privacy\Policy\SensitiveDataPolicyInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class ObservabilityModule
{
    /** @param array{enabled: bool, redaction_hmac_key: string} $config */
    public static function load(array $config, DefaultsConfigurator $services): void
    {
        if (!class_exists(LogRecord::class)) {
            throw new LogicException('nubit_admin.observability requires monolog/monolog.');
        }
        if (!interface_exists(TracerInterface::class)) {
            throw new LogicException('nubit_admin.observability requires open-telemetry/api.');
        }

        $services->set(SensitiveDataMetadataReader::class);
        $services->set(DefaultSensitiveDataPolicy::class);
        $services->alias(SensitiveDataPolicyInterface::class, DefaultSensitiveDataPolicy::class);
        $services->set(DataRedactor::class)->arg('$hmacKey', $config['redaction_hmac_key']);

        $services->set(SensitiveDataProcessor::class)->tag('monolog.processor', ['priority' => 100]);
        $services->set(TenantLogProcessor::class)->tag('monolog.processor', ['priority' => 200]);

        $services->set(TraceAttributeSanitizer::class);
        $services->set(TenantTracerFactory::class);
        $services->set(TenantTracer::class)->factory([service(TenantTracerFactory::class), 'create']);
        $services->set('nubit.observability.tracer', TracerInterface::class)->factory([
            service(TenantTracerFactory::class),
            'createTracer',
        ]);
        $services
            ->set(HttpRequestTracingListener::class)
            ->arg('$tracer', service('nubit.observability.tracer'))
            ->tag('kernel.event_listener', [
                'event' => 'kernel.request',
                'method' => 'onRequest',
                'priority' => 2048,
            ])
            ->tag('kernel.event_listener', [
                'event' => 'kernel.exception',
                'method' => 'onException',
                'priority' => -2048,
            ])
            ->tag('kernel.event_listener', [
                'event' => 'kernel.finish_request',
                'method' => 'onFinishRequest',
                'priority' => -2048,
            ])
            ->tag('kernel.event_listener', [
                'event' => 'kernel.response',
                'method' => 'onResponse',
                'priority' => -2048,
            ]);
    }
}
