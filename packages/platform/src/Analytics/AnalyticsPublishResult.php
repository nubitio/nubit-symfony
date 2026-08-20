<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics;

enum AnalyticsPublishResult: string
{
    case Captured = 'captured';
    case ConsentDenied = 'consent_denied';
    case Duplicate = 'duplicate';
    case InvalidPayload = 'invalid_payload';
    case ProviderFailed = 'provider_failed';
}
