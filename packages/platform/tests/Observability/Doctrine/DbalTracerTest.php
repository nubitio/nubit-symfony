<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Observability\Doctrine;

use Nubit\Platform\Observability\Doctrine\DbalTracer;
use Nubit\Platform\Observability\Tracing\TraceAttributeSanitizer;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Tenant\Context\TenantContext;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DbalTracerTest extends TestCase
{
    public function testExportsOnlyBoundedOperationalAttributes(): void
    {
        $captured = [];
        $tracer = $this->tracer($captured);
        $context = new TenantContext();
        $context->setTenant(42, 'customer@example.com', 'secret.example.test', 'req-42');

        $result = (new DbalTracer($tracer, $context, new TraceAttributeSanitizer(new DataRedactor())))->trace(
            'query',
            static fn(): string => 'result',
        );

        self::assertSame('result', $result);
        self::assertSame('doctrine_dbal', $captured['db.system.name']);
        self::assertSame('query', $captured['db.operation.name']);
        self::assertStringNotContainsString('customer@example.com', json_encode($captured, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret.example.test', json_encode($captured, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('db.query.text', $captured);
        self::assertArrayNotHasKey('server.address', $captured);
    }

    public function testRecordsOnlyExceptionClass(): void
    {
        $captured = [];
        $span = $this->createMock(SpanInterface::class);
        $span->method('activate')->willReturn($this->createStub(ScopeInterface::class));
        $span
            ->expects(self::once())
            ->method('addEvent')
            ->with('exception', [
                'exception.type' => RuntimeException::class,
            ])
            ->willReturnSelf();

        $dbalTracer = new DbalTracer(
            $this->tracer($captured, $span),
            new TenantContext(),
            new TraceAttributeSanitizer(new DataRedactor()),
        );

        $this->expectException(RuntimeException::class);
        $dbalTracer->trace(
            'prepared.execute',
            static fn(): never => throw new RuntimeException('SQLSTATE password=secret card=4111111111111111'),
        );
    }

    /** @param array<string, mixed> $captured */
    private function tracer(array &$captured, ?SpanInterface $span = null): TracerInterface
    {
        if (null === $span) {
            $span = $this->createStub(SpanInterface::class);
            $span->method('activate')->willReturn($this->createStub(ScopeInterface::class));
        }

        $builder = $this->createStub(SpanBuilderInterface::class);
        $builder->method('setSpanKind')->willReturnSelf();
        $builder
            ->method('setAttributes')
            ->willReturnCallback(static function (array $attributes) use (&$captured, $builder): SpanBuilderInterface {
                $captured = $attributes;

                return $builder;
            });
        $builder->method('startSpan')->willReturn($span);

        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('isEnabled')->willReturn(true);
        $tracer->method('spanBuilder')->willReturn($builder);

        return $tracer;
    }
}
