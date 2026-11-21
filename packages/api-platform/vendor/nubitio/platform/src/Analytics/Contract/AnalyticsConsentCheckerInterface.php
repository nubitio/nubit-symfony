<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics\Contract;

use Nubit\Platform\Analytics\AnalyticsPurpose;

interface AnalyticsConsentCheckerInterface
{
    public function allows(AnalyticsPurpose $purpose, ?int $tenantId): bool;
}
