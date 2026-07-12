<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Isolation;

/** The resolved data-placement target for one tenant. */
final readonly class TenantIsolationTarget
{
    public const string COLUMN = 'column';
    public const string DATABASE = 'database';
    public const string SCHEMA = 'schema';

    public function __construct(
        public string $mode,
        public ?string $databaseUrl = null,
    ) {
        if (!in_array($mode, [self::COLUMN, self::DATABASE, self::SCHEMA], strict: true)) {
            throw new \InvalidArgumentException(sprintf('Unknown tenant isolation mode "%s".', $mode));
        }

        if (self::DATABASE === $mode && (null === $databaseUrl || '' === trim($databaseUrl))) {
            throw new \InvalidArgumentException('Database tenant isolation requires a non-empty database URL.');
        }
    }
}
