<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Logging;

use Nubit\Platform\Tenant\Context\TenantContext;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\Span;

class TenantLogProcessor
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        $context['tenant_id'] = $this->tenantContext->getTenantId();
        $context['tenant'] = $this->tenantContext->getTenantName();
        $context['tenant_domain'] = $this->tenantContext->getTenantDomain();
        $context['request_id'] = $this->tenantContext->getRequestId();

        if (class_exists(Span::class)) {
            $spanContext = Span::getCurrent()->getContext();
            if ($spanContext->isValid()) {
                $context['trace_id'] = $spanContext->getTraceId();
                $context['span_id'] = $spanContext->getSpanId();
            }
        }

        return $record->with(context: $context);
    }
}
