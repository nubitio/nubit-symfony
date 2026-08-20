<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal */
final class TracingStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly DbalTracer $tracer,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        return $this->tracer->trace('prepared.execute', fn(): Result => parent::execute());
    }
}
