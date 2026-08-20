<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Analytics;

use LogicException;
use Nubit\Platform\Analytics\Contract\AnalyticsDeliveryProviderInterface;
use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;

final readonly class UnavailableAnalyticsDeliveryProvider implements AnalyticsDeliveryProviderInterface
{
    public function deliver(SanitizedAnalyticsEvent $event): void
    {
        throw new LogicException('Alias AnalyticsDeliveryProviderInterface to an application delivery provider.');
    }
}
