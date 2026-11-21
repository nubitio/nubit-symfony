<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Backup;

use Doctrine\DBAL\Connection;
use Nubit\Platform\Filesystem\FileManager;
use Nubit\Platform\Tenant\Contract\TenantBackupRunnerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * First concrete implementation of the previously-unimplemented
 * `TenantBackupRunnerInterface`. Deliberately narrow in scope:
 *
 * - PostgreSQL only (via `pg_dump --format=custom`). Throws rather than
 *   silently producing an incomplete dump against any other driver.
 * - Persists through the app's own `FileManager` (Flysystem, already
 *   tenant-path-aware — see `Nubit\Platform\Filesystem\FileManager`), so
 *   "local disk" vs "S3" is whatever filesystem the app already wired up for
 *   `nubit_admin.media.storage`, not a second storage config to maintain.
 *   `$uploadToS3` is read as "persist this dump at all" (false = dry run,
 *   dump to a temp file and discard) since the interface doesn't otherwise
 *   define a second, separate offsite destination.
 * - Reads DB credentials from Doctrine's own `Connection::getParams()`
 *   instead of re-parsing `DATABASE_URL`, and shells out via `Process` with
 *   an argument array (never a shell string) so a tenant name or password
 *   containing shell metacharacters can't inject commands. The password is
 *   passed via the `PGPASSWORD` env var, never argv, so it never shows up in
 *   `ps aux` or process listings.
 * - `id` in the returned array is a Unix timestamp, not an autoincrement
 *   row id — this runner has no backing "backup history" entity. Add one
 *   (and a real id sequence) if callers need to query past backups instead
 *   of just listing the configured filesystem.
 */
final readonly class PostgresTenantBackupRunner implements TenantBackupRunnerInterface
{
    public function __construct(
        private Connection $connection,
        private FileManager $fileManager,
        private string $pgDumpBinary = 'pg_dump',
        private int $timeoutSeconds = 300,
    ) {}

    /**
     * @return array{id: int, filename: string, storage_path: string, size_bytes: int, storage_type: string}
     */
    public function backup(string $tenantName, bool $uploadToS3 = true, string $backupType = 'full'): array
    {
        $this->assertPostgres();

        $dumpPath = tempnam(sys_get_temp_dir(), 'nubit-tenant-backup-');
        if ($dumpPath === false) {
            throw new \RuntimeException('Unable to allocate a temporary file for the database dump.');
        }

        try {
            $this->runPgDump($this->connection->getParams(), $dumpPath);

            $contents = file_get_contents($dumpPath);
            if ($contents === false) {
                throw new \RuntimeException('pg_dump exited successfully but produced no readable output file.');
            }

            $filename = $this->buildFilename($tenantName, $backupType);
            $storagePath = sprintf('backups/%s/%s', $tenantName, $filename);

            if ($uploadToS3) {
                $this->fileManager->write($storagePath, $contents);
            }

            return [
                'id' => (new \DateTimeImmutable())->getTimestamp(),
                'filename' => $filename,
                'storage_path' => $storagePath,
                'size_bytes' => \strlen($contents),
                'storage_type' => $uploadToS3 ? 'flysystem' : 'discarded',
            ];
        } finally {
            if (is_file($dumpPath)) {
                unlink($dumpPath);
            }
        }
    }

    private function assertPostgres(): void
    {
        $driver = $this->connection->getParams()['driver'] ?? '';
        if (!str_contains($driver, 'pgsql')) {
            throw new \RuntimeException(sprintf(
                'PostgresTenantBackupRunner only supports PostgreSQL connections (pg_dump), got driver "%s". '
                . 'Implement TenantBackupRunnerInterface with an engine-appropriate tool for other drivers.',
                $driver !== '' ? $driver : 'unknown',
            ));
        }
    }

    public function buildFilename(string $tenantName, string $backupType): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $tenantName));

        return sprintf('%s-%s-%s.dump', $slug, $backupType, (new \DateTimeImmutable())->format('Ymd-His'));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    public function buildPgDumpCommand(array $params, string $outputPath): array
    {
        return [
            $this->pgDumpBinary,
            '--host=' . (string) ($params['host'] ?? 'localhost'),
            '--port=' . (string) ($params['port'] ?? '5432'),
            '--username=' . (string) ($params['user'] ?? ''),
            '--format=custom',
            '--no-password',
            '--file=' . $outputPath,
            (string) ($params['dbname'] ?? $params['path'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function runPgDump(array $params, string $outputPath): void
    {
        $process = new Process($this->buildPgDumpCommand($params, $outputPath), env: [
            'PGPASSWORD' => (string) ($params['password'] ?? ''),
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
