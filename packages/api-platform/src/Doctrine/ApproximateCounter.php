<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * An estimate of how many rows a table holds, without reading them.
 *
 * `SELECT COUNT(*)` on PostgreSQL walks the whole relation — MVCC means the
 * count is not stored anywhere — so a grid's footer becomes the slowest part of
 * the request long before the rows themselves do. Three years of an ERP's
 * movements is a full scan on every page change.
 *
 * The estimate comes from the planner's own statistics, which are exactly what
 * the database uses to decide how to run the query in the first place. It is
 * wrong by a few percent between vacuums, which matters not at all for "about
 * 2.4 million" and enormously for the second it saves.
 *
 * Only an unfiltered count can be answered this way. A filtered one has to be
 * counted, and the caller is told so by a null return rather than by a number
 * that quietly ignores the filter.
 */
final readonly class ApproximateCounter
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function supportsPlatform(): bool
    {
        try {
            return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return int|null null when no estimate is available, which the caller must
     *                  read as "count it properly" rather than as zero
     */
    public function estimate(string $table): ?int
    {
        if (!$this->supportsPlatform() || !self::isPlainIdentifier($table)) {
            return null;
        }

        try {
            $estimate = $this->connection->fetchOne('SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass(?)', [
                $table,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->notice('Could not read a row estimate; falling back to an exact count.', [
                'table' => $table,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!is_numeric($estimate)) {
            return null;
        }

        $rows = (int) $estimate;

        // -1 is what PostgreSQL stores for a relation that has never been
        // analysed, and 0 is indistinguishable from a genuinely empty table.
        // Both are worth counting properly rather than reporting.
        return $rows > 0 ? $rows : null;
    }

    /**
     * Table names reach this from mapping metadata, not from a request — but a
     * value that ends up inside `to_regclass` is worth being sure about anyway.
     */
    private static function isPlainIdentifier(string $table): bool
    {
        return 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table);
    }
}
