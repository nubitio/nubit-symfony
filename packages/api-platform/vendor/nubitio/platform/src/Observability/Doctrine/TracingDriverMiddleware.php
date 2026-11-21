<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

final readonly class TracingDriverMiddleware implements Middleware
{
    public function __construct(
        private DbalTracer $tracer,
    ) {}

    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($driver, $this->tracer);
    }
}
