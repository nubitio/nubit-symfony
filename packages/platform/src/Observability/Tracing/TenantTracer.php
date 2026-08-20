<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Tracing;

use Nubit\Platform\Tenant\Context\TenantContext;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use InvalidArgumentException;
use Throwable;

final readonly class TenantTracer
{
    public function __construct(
        private TracerInterface $tracer,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @param array<non-empty-string, bool|int|float|string|array|null> $attributes
     * @return TResult
     */
    public function trace(string $spanName, callable $operation, array $attributes = []): mixed
    {
        $spanName = trim($spanName);
        if ('' === $spanName) {
            throw new InvalidArgumentException('OpenTelemetry span name must not be empty.');
        }

        $span = $this->tracer->spanBuilder($spanName)
            ->setAttributes($this->contextAttributes() + $attributes)
            ->startSpan();
        $scope = $span->activate();

        try {
            return $operation();
        } catch (Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /** @return array<non-empty-string, int|string|null> */
    private function contextAttributes(): array
    {
        return [
            'nubit.tenant.id' => $this->tenantContext->getTenantId(),
            'nubit.tenant.name' => $this->tenantContext->getTenantName(),
            'nubit.tenant.domain' => $this->tenantContext->getTenantDomain(),
            'nubit.request.id' => $this->tenantContext->getRequestId(),
        ];
    }
}
