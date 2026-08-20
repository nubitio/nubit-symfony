<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Analytics;

use DateTimeImmutable;
use Nubit\AdminBundle\Analytics\WebhookAnalyticsDeliveryProvider;
use Nubit\Platform\Analytics\AnalyticsPurpose;
use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WebhookAnalyticsDeliveryProviderTest extends TestCase
{
    public function testPostsStableSanitizedEnvelopeWithBearerToken(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(202);
        $client = $this->createMock(HttpClientInterface::class);
        $client
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://analytics.example.test/capture',
                self::callback(static function (array $options): bool {
                    $encoded = json_encode($options, JSON_THROW_ON_ERROR);

                    return (
                        str_contains($encoded, 'Bearer runtime-secret')
                        && str_contains($encoded, 'invoice.paid')
                        && str_contains($encoded, 'channel')
                        && str_contains($encoded, 'tenant_id')
                    );
                }),
            )
            ->willReturn($response);

        (new WebhookAnalyticsDeliveryProvider(
            $client,
            'https://analytics.example.test/capture',
            'runtime-secret',
        ))->deliver(
            new SanitizedAnalyticsEvent(
                'invoice-paid-42',
                'invoice.paid',
                1,
                AnalyticsPurpose::Operational,
                ['channel' => 'web'],
                new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
                42,
            ),
        );
    }

    public function testRejectsInsecureEndpointAndDoesNotExposeResponseBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebhookAnalyticsDeliveryProvider($this->createStub(HttpClientInterface::class), 'http://analytics.test');
    }

    public function testNonSuccessStatusFailsWithoutReadingProviderBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(503);
        $response->expects(self::never())->method('getContent');
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $provider = new WebhookAnalyticsDeliveryProvider($client, 'https://analytics.example.test/capture');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP status 503');
        $provider->deliver(
            new SanitizedAnalyticsEvent(
                'event-1',
                'invoice.paid',
                1,
                AnalyticsPurpose::Operational,
                [],
                new DateTimeImmutable(),
                null,
            ),
        );
    }
}
