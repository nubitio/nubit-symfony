<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Doctrine;

use Nubit\Platform\Observability\Tracing\TraceAttributeSanitizer;
use Nubit\Platform\Tenant\Context\TenantContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class DbalTracer
{
    public function __construct(
        private TracerInterface $tracer,
        private TenantContext $tenantContext,
        private TraceAttributeSanitizer $attributeSanitizer,
    ) {}

    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @return TResult
     */
    public function trace(string $operationName, callable $operation): mixed
    {
        if (!$this->tracer->isEnabled()) {
            return $operation();
        }

        $span = $this->tracer
            ->spanBuilder('db.' . $operationName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttributes($this->attributeSanitizer->sanitize([
                'db.system.name' => 'doctrine_dbal',
                'db.operation.name' => $operationName,
                'nubit.tenant.id' => $this->tenantContext->getTenantId(),
                'nubit.request.id' => $this->tenantContext->getRequestId(),
            ]))
            ->startSpan();
        $scope = $span->activate();

        try {
            return $operation();
        } catch (Throwable $exception) {
            $span->addEvent('exception', ['exception.type' => $exception::class]);
            $span->setStatus(StatusCode::STATUS_ERROR);

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
