<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Observability\Tracing;

use Nubit\Platform\Observability\Metrics\OperationalMetrics;
use Nubit\Platform\Observability\Tracing\HttpRequestTracingListener;
use Nubit\Platform\Observability\Tracing\TraceAttributeSanitizer;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Tenant\Context\TenantContext;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpRequestTracingListenerTest extends TestCase
{
    public function testTracesMainRequestWithoutCapturingRequestData(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $scope = $this->createMock(ScopeInterface::class);
        $span->method('activate')->willReturn($scope);
        $span->expects(self::once())->method('updateName')->with('GET api_orders')->willReturnSelf();
        $span
            ->expects(self::once())
            ->method('setAttributes')
            ->with(self::callback(
                static fn(array $attributes): bool => 204 === $attributes['http.response.status_code']
                && !str_contains(json_encode($attributes, JSON_THROW_ON_ERROR), 'customer@example.com'),
            ))
            ->willReturnSelf();
        $scope->expects(self::once())->method('detach');
        $span->expects(self::once())->method('end');

        $listener = $this->listener($span);
        $request = Request::create('/orders?email=customer@example.com', 'GET');
        $request->headers->set('Authorization', 'Bearer secret-token');
        $request->headers->set('traceparent', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        $request->attributes->set('_route', 'api_orders');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onResponse(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response(status: 204)),
        );
    }

    public function testRecordsOnlyExceptionType(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->method('activate')->willReturn($this->createStub(ScopeInterface::class));
        $span
            ->expects(self::once())
            ->method('addEvent')
            ->with('exception', [
                'exception.type' => RuntimeException::class,
            ])
            ->willReturnSelf();

        $listener = $this->listener($span);
        $request = Request::create('/orders', 'POST');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onException(
            new ExceptionEvent(
                $kernel,
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                new RuntimeException('card=4111111111111111'),
            ),
        );
    }

    private function listener(SpanInterface $span): HttpRequestTracingListener
    {
        $builder = $this->createStub(SpanBuilderInterface::class);
        $builder->method('setParent')->willReturnSelf();
        $builder->method('setSpanKind')->willReturnSelf();
        $builder->method('setAttributes')->willReturnSelf();
        $builder->method('startSpan')->willReturn($span);

        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('isEnabled')->willReturn(true);
        $tracer->method('spanBuilder')->willReturn($builder);

        $propagator = $this->createStub(TextMapPropagatorInterface::class);
        $propagator->method('fields')->willReturn(['traceparent', 'tracestate']);
        $propagator->method('extract')->willReturn(Context::getRoot());

        return new HttpRequestTracingListener(
            $tracer,
            new TenantContext(),
            new TraceAttributeSanitizer(new DataRedactor()),
            new OperationalMetrics(new NoopMeter()),
            $propagator,
        );
    }
}
