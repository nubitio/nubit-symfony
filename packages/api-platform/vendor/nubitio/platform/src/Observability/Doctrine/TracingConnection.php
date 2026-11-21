<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal */
final class TracingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly DbalTracer $tracer,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new TracingStatement(parent::prepare($sql), $this->tracer);
    }

    public function query(string $sql): Result
    {
        return $this->tracer->trace('query', fn(): Result => parent::query($sql));
    }

    public function exec(string $sql): int|string
    {
        return $this->tracer->trace('exec', fn(): int|string => parent::exec($sql));
    }

    public function beginTransaction(): void
    {
        $this->tracer->trace('transaction.begin', fn() => parent::beginTransaction());
    }

    public function commit(): void
    {
        $this->tracer->trace('transaction.commit', fn() => parent::commit());
    }

    public function rollBack(): void
    {
        $this->tracer->trace('transaction.rollback', fn() => parent::rollBack());
    }
}
