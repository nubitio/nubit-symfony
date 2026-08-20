<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics;

use Nubit\Platform\Analytics\Contract\AnalyticsConsentCheckerInterface;

final readonly class OperationalOnlyConsentChecker implements AnalyticsConsentCheckerInterface
{
    public function allows(AnalyticsPurpose $purpose, ?int $tenantId): bool
    {
        return AnalyticsPurpose::Operational === $purpose;
    }
}
