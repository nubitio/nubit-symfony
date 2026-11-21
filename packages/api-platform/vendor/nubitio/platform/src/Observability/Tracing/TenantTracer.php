<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Tracing;

use InvalidArgumentException;
use Nubit\Platform\Tenant\Context\TenantContext;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class TenantTracer
{
    public function __construct(
        private TracerInterface $tracer,
        private TenantContext $tenantContext,
        private ?TraceAttributeSanitizer $attributeSanitizer = null,
    ) {}

    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @param array<string, mixed> $attributes
     * @return TResult
     */
    public function trace(string $spanName, callable $operation, array $attributes = []): mixed
    {
        $spanName = trim($spanName);
        if ('' === $spanName) {
            throw new InvalidArgumentException('OpenTelemetry span name must not be empty.');
        }

        $span = $this->tracer
            ->spanBuilder($spanName)
            ->setAttributes($this->sanitizeAttributes($this->contextAttributes() + $attributes))
            ->startSpan();
        $scope = $span->activate();

        try {
            return $operation();
        } catch (Throwable $exception) {
            // Exception messages are arbitrary text and may contain sensitive data.
            $span->addEvent('exception', ['exception.type' => $exception::class]);
            $span->setStatus(StatusCode::STATUS_ERROR);

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
            'nubit.request.id' => $this->tenantContext->getRequestId(),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    // @mago-expect analysis:mixed-assignment
    private function sanitizeAttributes(array $attributes): array
    {
        if (null !== $this->attributeSanitizer) {
            return $this->attributeSanitizer->sanitize($attributes);
        }

        $result = [];
        foreach ($attributes as $key => $value) {
            if ('' !== $key && (null === $value || is_scalar($value) || is_array($value))) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
