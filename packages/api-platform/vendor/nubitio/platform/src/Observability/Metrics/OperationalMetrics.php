<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Metrics;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;

final readonly class OperationalMetrics
{
    private CounterInterface $httpRequests;
    private CounterInterface $httpErrors;
    private HistogramInterface $httpDuration;
    private CounterInterface $messagingOperations;
    private CounterInterface $messagingErrors;
    private HistogramInterface $messagingDuration;
    private CounterInterface $databaseOperations;
    private CounterInterface $databaseErrors;
    private HistogramInterface $databaseDuration;

    public function __construct(MeterInterface $meter)
    {
        $this->httpRequests = $meter->createCounter('nubit.http.server.requests', '{request}');
        $this->httpErrors = $meter->createCounter('nubit.http.server.errors', '{error}');
        $this->httpDuration = $meter->createHistogram('nubit.http.server.duration', 's');
        $this->messagingOperations = $meter->createCounter('nubit.messaging.operations', '{operation}');
        $this->messagingErrors = $meter->createCounter('nubit.messaging.errors', '{error}');
        $this->messagingDuration = $meter->createHistogram('nubit.messaging.duration', 's');
        $this->databaseOperations = $meter->createCounter('nubit.db.client.operations', '{operation}');
        $this->databaseErrors = $meter->createCounter('nubit.db.client.errors', '{error}');
        $this->databaseDuration = $meter->createHistogram('nubit.db.client.duration', 's');
    }

    public function recordHttp(string $method, string $route, int $statusCode, float $duration): void
    {
        $attributes = [
            'http.request.method' => self::httpMethod($method),
            'http.route' => '' !== trim($route) ? $route : 'unmatched',
            'http.response.status_code' => $statusCode,
        ];
        $this->httpRequests->add(1, $attributes);
        $this->httpDuration->record(max(0.0, $duration), $attributes);
        if ($statusCode >= 500) {
            $this->httpErrors->add(1, $attributes);
        }
    }

    public function recordMessaging(string $operation, string $messageType, bool $failed, float $duration): void
    {
        $attributes = [
            'messaging.operation.type' => $operation,
            'messaging.message.type' => $messageType,
            'error.type' => $failed ? 'failure' : 'none',
        ];
        $this->messagingOperations->add(1, $attributes);
        $this->messagingDuration->record(max(0.0, $duration), $attributes);
        if ($failed) {
            $this->messagingErrors->add(1, $attributes);
        }
    }

    public function recordDatabase(string $operation, bool $failed, float $duration): void
    {
        $attributes = [
            'db.operation.name' => $operation,
            'error.type' => $failed ? 'failure' : 'none',
        ];
        $this->databaseOperations->add(1, $attributes);
        $this->databaseDuration->record(max(0.0, $duration), $attributes);
        if ($failed) {
            $this->databaseErrors->add(1, $attributes);
        }
    }

    private static function httpMethod(string $method): string
    {
        $method = strtoupper($method);

        return in_array($method, ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'], true)
            ? $method
            : '_OTHER';
    }
}
