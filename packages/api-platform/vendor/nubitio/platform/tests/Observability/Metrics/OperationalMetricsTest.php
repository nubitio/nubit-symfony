<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Observability\Metrics;

use Nubit\Platform\Observability\Metrics\OperationalMetrics;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopCounter;
use OpenTelemetry\API\Metrics\Noop\NoopHistogram;
use PHPUnit\Framework\TestCase;

final class OperationalMetricsTest extends TestCase
{
    public function testHttpRedMetricsUseOnlyBoundedDimensions(): void
    {
        $expected = [
            'http.request.method' => '_OTHER',
            'http.route' => 'api_orders',
            'http.response.status_code' => 503,
        ];
        $requests = $this->createMock(CounterInterface::class);
        $requests->expects(self::once())->method('add')->with(1, $expected);
        $errors = $this->createMock(CounterInterface::class);
        $errors->expects(self::once())->method('add')->with(1, $expected);
        $duration = $this->createMock(HistogramInterface::class);
        $duration->expects(self::once())->method('record')->with(0.25, $expected);

        $meter = $this->createStub(MeterInterface::class);
        $meter
            ->method('createCounter')
            ->willReturnCallback(static fn(string $name): CounterInterface => match ($name) {
                'nubit.http.server.requests' => $requests,
                'nubit.http.server.errors' => $errors,
                default => new NoopCounter(),
            });
        $meter
            ->method('createHistogram')
            ->willReturnCallback(static fn(string $name): HistogramInterface => 'nubit.http.server.duration' === $name
                ? $duration
                : new NoopHistogram());

        (new OperationalMetrics($meter))->recordHttp('CUSTOM', 'api_orders', 503, 0.25);
    }
}
