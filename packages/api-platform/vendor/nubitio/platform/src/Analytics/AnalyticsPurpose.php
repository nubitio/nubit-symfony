<?php

declare(strict_types=1);

namespace Nubit\Platform\Analytics;

enum AnalyticsPurpose: string
{
    case Operational = 'operational';
    case Product = 'product';
    case Marketing = 'marketing';
}
