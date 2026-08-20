<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Tracing;

use Nubit\Platform\Tenant\Context\TenantContext;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use WeakMap;

final class HttpRequestTracingListener
{
    /** @var WeakMap<Request, HttpRequestSpanState> */
    private WeakMap $activeSpans;

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly TenantContext $tenantContext,
        private readonly TraceAttributeSanitizer $attributeSanitizer,
        private readonly ?TextMapPropagatorInterface $propagator = null,
    ) {
        $this->activeSpans = new WeakMap();
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracer->isEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $method = strtoupper($request->getMethod());
        $propagator = $this->propagator ?? Globals::propagator();
        $carrier = [];
        foreach ($propagator->fields() as $field) {
            $value = $request->headers->get($field);
            if (null !== $value) {
                $carrier[$field] = $value;
            }
        }

        $span = $this->tracer
            ->spanBuilder('HTTP ' . $method)
            ->setParent($propagator->extract($carrier))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes($this->attributeSanitizer->sanitize([
                'http.request.method' => $method,
            ]))
            ->startSpan();

        $this->activeSpans[$request] = new HttpRequestSpanState($span, $span->activate());
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $state = $this->activeSpans[$event->getRequest()] ?? null;
        if (null === $state) {
            return;
        }

        $state->span->addEvent('exception', [
            'exception.type' => $event->getThrowable()::class,
        ]);
        $state->span->setStatus(StatusCode::STATUS_ERROR);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $state = $this->activeSpans[$request] ?? null;
        if (null === $state) {
            return;
        }

        $route = $request->attributes->getString('_route');
        $statusCode = $event->getResponse()->getStatusCode();
        $attributes = [
            'http.response.status_code' => $statusCode,
            'nubit.tenant.id' => $this->tenantContext->getTenantId(),
            'nubit.tenant.name' => $this->tenantContext->getTenantName(),
            'nubit.tenant.domain' => $this->tenantContext->getTenantDomain(),
            'nubit.request.id' => $this->tenantContext->getRequestId(),
        ];

        if ('' !== trim($route)) {
            $attributes['http.route'] = $route;
            $state->span->updateName($request->getMethod() . ' ' . $route);
        }

        $state->span->setAttributes($this->attributeSanitizer->sanitize($attributes));
        if ($statusCode >= 500) {
            $state->span->setStatus(StatusCode::STATUS_ERROR);
        }

        $this->finish($request, $state);
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $state = $this->activeSpans[$request] ?? null;
        if (null !== $state) {
            $this->finish($request, $state);
        }
    }

    private function finish(Request $request, HttpRequestSpanState $state): void
    {
        $state->scope->detach();
        $state->span->end();
        unset($this->activeSpans[$request]);
    }
}
