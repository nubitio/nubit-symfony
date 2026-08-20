<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Tracing;

use Nubit\Platform\Tenant\Context\TenantContext;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;

final readonly class TenantTracerFactory
{
    public function __construct(
        private TenantContext $tenantContext,
        private TraceAttributeSanitizer $attributeSanitizer,
    ) {}

    public function create(): TenantTracer
    {
        return new TenantTracer($this->createTracer(), $this->tenantContext, $this->attributeSanitizer);
    }

    public function createTracer(): TracerInterface
    {
        return Globals::tracerProvider()->getTracer('nubitio/platform');
    }
}
