<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy;

enum DataClassification: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Restricted = 'restricted';
}
