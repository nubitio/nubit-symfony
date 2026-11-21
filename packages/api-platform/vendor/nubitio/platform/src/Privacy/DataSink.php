<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy;

enum DataSink: string
{
    case Log = 'log';
    case Trace = 'trace';
    case Metric = 'metric';
    case Analytics = 'analytics';
    case Audit = 'audit';
    case Webhook = 'webhook';
    case Export = 'export';
}
