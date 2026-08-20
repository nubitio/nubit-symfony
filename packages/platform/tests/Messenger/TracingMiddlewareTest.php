<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Messenger;

use Nubit\Platform\Messenger\TraceContextStamp;
use Nubit\Platform\Messenger\TracingMiddleware;
use Nubit\Platform\Observability\Tracing\TraceAttributeSanitizer;
use Nubit\Platform\Privacy\DataRedactor;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class TracingMiddlewareTest extends TestCase
{
    public function testProducerAddsOnlyW3cTraceContextStamp(): void
    {
        $propagator = $this->createStub(TextMapPropagatorInterface::class);
        $propagator
            ->method('inject')
            ->willReturnCallback(static function (mixed &$carrier): void {
                $carrier = [
                    'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
                    'tracestate' => 'vendor=value',
                    'baggage' => 'email=customer@example.com',
                ];
            });

        $result = $this->middleware($propagator)->handle(
            new Envelope(new SensitiveMessage('customer@example.com', 'secret-token')),
            new MessengerPassthroughStack(),
        );

        $stamp = $result->last(TraceContextStamp::class);
        self::assertNotNull($stamp);
        self::assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $stamp->traceparent);
        self::assertSame('vendor=value', $stamp->tracestate);
        self::assertArrayNotHasKey('baggage', $stamp->toCarrier());
    }

    public function testConsumerUsesPropagatedParentWithoutReadingMessagePayload(): void
    {
        $propagator = $this->createMock(TextMapPropagatorInterface::class);
        $propagator
            ->expects(self::once())
            ->method('extract')
            ->with([
                'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            ])
            ->willReturn(Context::getRoot());

        $this->middleware($propagator)->handle(new Envelope(
            new SensitiveMessage('customer@example.com', 'secret-token'),
            [
                new ReceivedStamp('async'),
                new TraceContextStamp('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'),
            ],
        ), new MessengerPassthroughStack());
    }

    private function middleware(TextMapPropagatorInterface $propagator): TracingMiddleware
    {
        $span = $this->createStub(SpanInterface::class);
        $span->method('activate')->willReturn($this->createStub(ScopeInterface::class));

        $builder = $this->createMock(SpanBuilderInterface::class);
        $builder->method('setParent')->willReturnSelf();
        $builder->expects(self::once())->method('setSpanKind')->willReturnSelf();
        $builder
            ->expects(self::once())
            ->method('setAttributes')
            ->with(self::callback(
                static fn(array $attributes): bool => (
                    !str_contains(json_encode($attributes, JSON_THROW_ON_ERROR), 'customer@example.com')
                    && !str_contains(json_encode($attributes, JSON_THROW_ON_ERROR), 'secret-token')
                ),
            ))
            ->willReturnSelf();
        $builder->expects(self::once())->method('startSpan')->willReturn($span);

        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('isEnabled')->willReturn(true);
        $tracer->method('spanBuilder')->willReturn($builder);

        return new TracingMiddleware($tracer, new TraceAttributeSanitizer(new DataRedactor()), $propagator);
    }
}

/** @internal */
final readonly class SensitiveMessage
{
    public function __construct(
        public string $email,
        public string $token,
    ) {}
}

/** @internal */
final class MessengerPassthroughStack implements StackInterface
{
    public function next(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope;
            }
        };
    }
}
