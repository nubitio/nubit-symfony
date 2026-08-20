<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;

/** @internal */
final readonly class HttpRequestSpanState
{
    public function __construct(
        public SpanInterface $span,
        public ScopeInterface $scope,
    ) {}
}
