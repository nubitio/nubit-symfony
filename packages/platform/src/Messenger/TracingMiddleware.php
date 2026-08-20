<?php

declare(strict_types=1);

namespace Nubit\Platform\Messenger;

use Nubit\Platform\Observability\Tracing\TraceAttributeSanitizer;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

final readonly class TracingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TracerInterface $tracer,
        private TraceAttributeSanitizer $attributeSanitizer,
        private ?TextMapPropagatorInterface $propagator = null,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->tracer->isEnabled()) {
            return $stack->next()->handle($envelope, $stack);
        }

        $consumer = null !== $envelope->last(ReceivedStamp::class);
        $messageType = $envelope->getMessage()::class;
        $operation = $consumer ? 'process' : 'send';
        $propagator = $this->propagator ?? Globals::propagator();
        $builder = $this->tracer
            ->spanBuilder($messageType . ' ' . $operation)
            ->setSpanKind($consumer ? SpanKind::KIND_CONSUMER : SpanKind::KIND_PRODUCER)
            ->setAttributes($this->attributeSanitizer->sanitize($this->attributes(
                $envelope,
                $messageType,
                $operation,
            )));

        if ($consumer) {
            $traceStamp = $envelope->last(TraceContextStamp::class);
            if (null !== $traceStamp) {
                $builder->setParent($propagator->extract($traceStamp->toCarrier()));
            }
        }

        $span = $builder->startSpan();
        $scope = $span->activate();

        if (!$consumer) {
            $carrier = [];
            // Propagators accept mixed carriers by contract and may replace the value by reference.
            // @mago-expect analysis:mixed-assignment
            $propagator->inject($carrier);
            if (!is_array($carrier)) {
                $carrier = [];
            }
            $envelope = $envelope
                ->withoutAll(TraceContextStamp::class)
                ->with(
                    new TraceContextStamp(
                        self::carrierValue($carrier, 'traceparent'),
                        self::carrierValue($carrier, 'tracestate'),
                    ),
                );
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (Throwable $exception) {
            $span->addEvent('exception', ['exception.type' => $exception::class]);
            $span->setStatus(StatusCode::STATUS_ERROR);

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /** @return array<string, mixed> */
    private function attributes(Envelope $envelope, string $messageType, string $operation): array
    {
        $tenantStamp = $envelope->last(TenantStamp::class);

        return [
            'messaging.system' => 'symfony_messenger',
            'messaging.operation.type' => $operation,
            'messaging.message.type' => $messageType,
            'nubit.tenant.id' => $tenantStamp?->tenantId,
            'nubit.tenant.name' => $tenantStamp?->tenantName,
            'nubit.tenant.domain' => $tenantStamp?->tenantDomain,
            'nubit.request.id' => $tenantStamp?->requestId,
        ];
    }

    /** @param array<array-key, mixed> $carrier */
    private static function carrierValue(array $carrier, string $key): ?string
    {
        if (!isset($carrier[$key]) || !is_string($carrier[$key])) {
            return null;
        }

        $value = $carrier[$key];

        return '' !== $value ? $value : null;
    }
}
