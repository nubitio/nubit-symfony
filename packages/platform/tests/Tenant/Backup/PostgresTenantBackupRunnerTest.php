<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Tenant\Backup;

use Doctrine\DBAL\Connection;
use Nubit\Platform\Filesystem\FileManager;
use Nubit\Platform\Tenant\Backup\PostgresTenantBackupRunner;
use PHPUnit\Framework\TestCase;

final class PostgresTenantBackupRunnerTest extends TestCase
{
    public function testRefusesToRunAgainstANonPostgresConnection(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_mysql']);
        $fileManager = $this->createStub(FileManager::class);

        $runner = new PostgresTenantBackupRunner($connection, $fileManager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/only supports PostgreSQL/');

        $runner->backup('acme');
    }

    public function testBuildsAPgDumpCommandAsAnArgumentArrayWithNoShellInterpolation(): void
    {
        $connection = $this->createStub(Connection::class);
        $fileManager = $this->createStub(FileManager::class);
        $runner = new PostgresTenantBackupRunner($connection, $fileManager, pgDumpBinary: '/usr/bin/pg_dump');

        $command = $runner->buildPgDumpCommand([
            'host' => 'db.internal',
            'port' => '5433',
            'user' => 'nubit',
            'dbname' => 'acme_prod',
        ], '/tmp/dump-file');

        // Every value is its own array element (Symfony\Process never
        // concatenates these into a shell string), so no value here — not
        // even a maliciously crafted tenant/db name — can break out into a
        // second command.
        static::assertSame(
            [
                '/usr/bin/pg_dump',
                '--host=db.internal',
                '--port=5433',
                '--username=nubit',
                '--format=custom',
                '--no-password',
                '--file=/tmp/dump-file',
                'acme_prod',
            ],
            $command,
        );
    }

    public function testBuildPgDumpCommandFallsBackToDefaultsForMissingParams(): void
    {
        $connection = $this->createStub(Connection::class);
        $fileManager = $this->createStub(FileManager::class);
        $runner = new PostgresTenantBackupRunner($connection, $fileManager);

        $command = $runner->buildPgDumpCommand([], '/tmp/out');

        static::assertSame('--host=localhost', $command[1]);
        static::assertSame('--port=5432', $command[2]);
    }

    public function testFilenameIsSlugifiedAndIncludesTheBackupType(): void
    {
        $connection = $this->createStub(Connection::class);
        $fileManager = $this->createStub(FileManager::class);
        $runner = new PostgresTenantBackupRunner($connection, $fileManager);

        $filename = $runner->buildFilename('Acme Corp / Prod', 'incremental');

        // A run of non-alnum chars (the two spaces + slash around "/")
        // collapses to a single dash — "acme-corp-prod", not "acme-corp---prod".
        static::assertMatchesRegularExpression('/^acme-corp-prod-incremental-\d{8}-\d{6}\.dump$/', $filename);
    }
}
