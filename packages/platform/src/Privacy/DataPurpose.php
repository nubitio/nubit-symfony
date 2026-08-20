<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy;

enum DataPurpose: string
{
    case Operational = 'operational';
    case Audit = 'audit';
    case Analytics = 'analytics';
    case Export = 'export';
    case Integration = 'integration';
}
