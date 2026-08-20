<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Observability\Tracing;

use Nubit\Platform\Observability\Tracing\TenantTracer;
use Nubit\Platform\Tenant\Context\TenantContext;
use OpenTelemetry\API\Trace\NoopTracer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use InvalidArgumentException;

final class TenantTracerTest extends TestCase
{
    public function testReturnsOperationResultWithoutAnInstalledSdk(): void
    {
        $tracer = new TenantTracer(new NoopTracer(), new TenantContext());

        self::assertSame('done', $tracer->trace('test.operation', static fn (): string => 'done'));
    }

    public function testRethrowsOperationFailure(): void
    {
        $tracer = new TenantTracer(new NoopTracer(), new TenantContext());

        $this->expectException(RuntimeException::class);
        $tracer->trace('test.failure', static fn (): never => throw new RuntimeException('failed'));
    }

    public function testRejectsEmptySpanName(): void
    {
        $tracer = new TenantTracer(new NoopTracer(), new TenantContext());

        $this->expectException(InvalidArgumentException::class);
        $tracer->trace('  ', static fn (): null => null);
    }
}
