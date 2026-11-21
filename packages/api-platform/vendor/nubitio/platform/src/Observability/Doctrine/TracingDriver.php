<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/** @internal */
final class TracingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly DbalTracer $tracer,
    ) {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return $this->tracer->trace(
            'connect',
            fn(): Connection => new TracingConnection(parent::connect($params), $this->tracer),
        );
    }
}
