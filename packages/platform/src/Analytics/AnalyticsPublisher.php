<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics;

use Nubit\Platform\Analytics\Contract\AnalyticsConsentCheckerInterface;
use Nubit\Platform\Analytics\Contract\AnalyticsDeduplicatorInterface;
use Nubit\Platform\Analytics\Contract\AnalyticsProviderInterface;
use Nubit\Platform\Privacy\DataPurpose;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Privacy\DataSink;
use Nubit\Platform\Tenant\Context\TenantContext;
use Throwable;

final readonly class AnalyticsPublisher
{
    public function __construct(
        private AnalyticsProviderInterface $provider,
        private AnalyticsConsentCheckerInterface $consentChecker,
        private AnalyticsDeduplicatorInterface $deduplicator,
        private DataRedactor $redactor,
        private TenantContext $tenantContext,
    ) {}

    public function publish(AnalyticsEvent $event): AnalyticsPublishResult
    {
        $tenantId = $this->tenantContext->getTenantId();
        if (!$this->consentChecker->allows($event->purpose, $tenantId)) {
            return AnalyticsPublishResult::ConsentDenied;
        }
        if (!$this->deduplicator->claim($event->id)) {
            return AnalyticsPublishResult::Duplicate;
        }

        // The redactor intentionally accepts and returns mixed; the array check is the trust boundary.
        // @mago-expect analysis:mixed-assignment
        $properties = $this->redactor->redact($event->payload, DataSink::Analytics, DataPurpose::Analytics);
        if (!is_array($properties)) {
            return AnalyticsPublishResult::InvalidPayload;
        }

        try {
            /** @var array<string, mixed> $safeProperties */
            $safeProperties = self::stringKeys($properties);
            $this->provider->capture(new SanitizedAnalyticsEvent(
                id: $event->id,
                name: $event->name,
                schemaVersion: $event->schemaVersion,
                purpose: $event->purpose,
                properties: $safeProperties,
                occurredAt: $event->occurredAt,
                tenantId: $tenantId,
            ));
        } catch (Throwable) {
            return AnalyticsPublishResult::ProviderFailed;
        }

        return AnalyticsPublishResult::Captured;
    }

    /** @param array<array-key, mixed> $properties @return array<string, mixed> */
    // Analytics properties are intentionally mixed after each key is narrowed to string.
    // @mago-expect analysis:mixed-assignment
    private static function stringKeys(array $properties): array
    {
        $result = [];
        foreach ($properties as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
