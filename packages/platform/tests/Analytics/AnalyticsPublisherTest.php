<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Analytics;

use Nubit\Platform\Analytics\AnalyticsEvent;
use Nubit\Platform\Analytics\AnalyticsPublisher;
use Nubit\Platform\Analytics\AnalyticsPublishResult;
use Nubit\Platform\Analytics\AnalyticsPurpose;
use Nubit\Platform\Analytics\Contract\AnalyticsConsentCheckerInterface;
use Nubit\Platform\Analytics\Contract\AnalyticsProviderInterface;
use Nubit\Platform\Analytics\InMemoryAnalyticsDeduplicator;
use Nubit\Platform\Analytics\OperationalOnlyConsentChecker;
use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;
use Nubit\Platform\Privacy\Attribute\SensitiveData;
use Nubit\Platform\Privacy\DataClassification;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Tenant\Context\TenantContext;
use PHPUnit\Framework\TestCase;

final class AnalyticsPublisherTest extends TestCase
{
    public function testProviderReceivesOnlySanitizedTypedPayload(): void
    {
        $provider = new RecordingAnalyticsProvider();
        $context = new TenantContext();
        $context->setTenant(42, 'tenant@example.com', 'secret.example.test', 'req-42');
        $publisher = new AnalyticsPublisher(
            $provider,
            new OperationalOnlyConsentChecker(),
            new InMemoryAnalyticsDeduplicator(),
            new DataRedactor(hmacKey: 'analytics-hmac-key'),
            $context,
        );

        $result = $publisher->publish(new AnalyticsEvent(
            id: 'order-created-42',
            name: 'order.created',
            schemaVersion: 1,
            purpose: AnalyticsPurpose::Operational,
            payload: new OrderCreatedAnalyticsPayload('web', 'customer@example.com', '4111111111111111'),
        ));

        self::assertSame(AnalyticsPublishResult::Captured, $result);
        self::assertNotNull($provider->lastEvent);
        self::assertSame(42, $provider->lastEvent->tenantId);
        self::assertSame('web', $provider->lastEvent->properties['channel']);
        self::assertStringContainsString('hmac-sha256:', json_encode(
            $provider->lastEvent->properties,
            JSON_THROW_ON_ERROR,
        ));
        self::assertArrayNotHasKey('cardNumber', $provider->lastEvent->properties);
        self::assertStringNotContainsString('customer@example.com', json_encode(
            $provider->lastEvent->properties,
            JSON_THROW_ON_ERROR,
        ));
    }

    public function testConsentIsCheckedBeforeDeduplicationAndCapture(): void
    {
        $provider = new RecordingAnalyticsProvider();
        $deduplicator = new InMemoryAnalyticsDeduplicator();
        $publisher = new AnalyticsPublisher(
            $provider,
            new OperationalOnlyConsentChecker(),
            $deduplicator,
            new DataRedactor(),
            new TenantContext(),
        );
        $event = new AnalyticsEvent(
            'product-viewed-1',
            'product.viewed',
            1,
            AnalyticsPurpose::Product,
            new \stdClass(),
        );

        self::assertSame(AnalyticsPublishResult::ConsentDenied, $publisher->publish($event));
        self::assertTrue($deduplicator->claim($event->id));
        self::assertNull($provider->lastEvent);
    }

    public function testDuplicateEventIsNotCapturedTwice(): void
    {
        $provider = new RecordingAnalyticsProvider();
        $publisher = new AnalyticsPublisher(
            $provider,
            new AllowAllAnalyticsConsentChecker(),
            new InMemoryAnalyticsDeduplicator(),
            new DataRedactor(),
            new TenantContext(),
        );
        $event = new AnalyticsEvent('invoice-paid-1', 'invoice.paid', 1, AnalyticsPurpose::Product, new \stdClass());

        self::assertSame(AnalyticsPublishResult::Captured, $publisher->publish($event));
        self::assertSame(AnalyticsPublishResult::Duplicate, $publisher->publish($event));
        self::assertSame(1, $provider->captures);
    }
}

/** @internal */
final readonly class OrderCreatedAnalyticsPayload
{
    public function __construct(
        public string $channel,
        #[SensitiveData(DataClassification::Confidential)]
        public string $customerEmail,
        #[SensitiveData(DataClassification::Restricted)]
        public string $cardNumber,
    ) {}
}

/** @internal */
final class RecordingAnalyticsProvider implements AnalyticsProviderInterface
{
    public ?SanitizedAnalyticsEvent $lastEvent = null;
    public int $captures = 0;

    public function capture(SanitizedAnalyticsEvent $event): void
    {
        $this->lastEvent = $event;
        ++$this->captures;
    }
}

/** @internal */
final readonly class AllowAllAnalyticsConsentChecker implements AnalyticsConsentCheckerInterface
{
    public function allows(AnalyticsPurpose $purpose, ?int $tenantId): bool
    {
        return true;
    }
}
