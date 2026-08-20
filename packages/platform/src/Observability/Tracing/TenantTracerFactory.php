<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Tracing;

use Nubit\Platform\Tenant\Context\TenantContext;
use OpenTelemetry\API\Globals;

final readonly class TenantTracerFactory
{
    public function __construct(
        private TenantContext $tenantContext,
        private TraceAttributeSanitizer $attributeSanitizer,
    ) {}

    public function create(): TenantTracer
    {
        return new TenantTracer(
            Globals::tracerProvider()->getTracer('nubitio/platform'),
            $this->tenantContext,
            $this->attributeSanitizer,
        );
    }
}
