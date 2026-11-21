<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics\Contract;

use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;

interface AnalyticsProviderInterface
{
    public function capture(SanitizedAnalyticsEvent $event): void;
}
