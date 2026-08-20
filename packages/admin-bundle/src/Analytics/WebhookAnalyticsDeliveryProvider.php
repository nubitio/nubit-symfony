<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Analytics;

use InvalidArgumentException;
use Nubit\Platform\Analytics\Contract\AnalyticsDeliveryProviderInterface;
use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;
use RuntimeException;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WebhookAnalyticsDeliveryProvider implements AnalyticsDeliveryProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpoint,
        #[SensitiveParameter]
        private string $token = '',
        private float $timeout = 5.0,
        bool $allowInsecureHttp = false,
    ) {
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host) || !($allowInsecureHttp && 'http' === $scheme) && 'https' !== $scheme) {
            throw new InvalidArgumentException('Analytics delivery endpoint must be an absolute HTTPS URL.');
        }
        if ($timeout <= 0.0 || $timeout > 30.0) {
            throw new InvalidArgumentException(
                'Analytics delivery timeout must be greater than zero and at most 30 seconds.',
            );
        }
    }

    public function deliver(SanitizedAnalyticsEvent $event): void
    {
        $headers = ['Content-Type' => 'application/json'];
        if ('' !== $this->token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $response = $this->httpClient->request('POST', $this->endpoint, [
            'headers' => $headers,
            'json' => [
                'event_id' => $event->id,
                'event' => $event->name,
                'schema_version' => $event->schemaVersion,
                'purpose' => $event->purpose->value,
                'occurred_at' => $event->occurredAt->format(DATE_ATOM),
                'tenant_id' => $event->tenantId,
                'properties' => $event->properties,
            ],
            'timeout' => $this->timeout,
        ]);
        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf('Analytics delivery failed with HTTP status %d.', $statusCode));
        }
    }
}
