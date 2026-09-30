<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Sequence;

use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\SequenceBundle\NubitSequenceBundle;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The property `#[Sequence]` sells is "transaction-safe": two documents never
 * get the same number, and no number is skipped.
 *
 * Unit tests cannot show it — they run one process against one connection. This
 * spawns real PHP processes, each with its own PostgreSQL connection, released
 * at the same instant, so they contend for the counter row exactly as parallel
 * PHP-FPM workers would. That includes the first allocation of a scope, where
 * every worker races to insert the counter row.
 */
#[CoversNothing]
final class SequenceConcurrencyTest extends IntegrationTestCase
{
    private const int WORKERS = 6;
    private const int PER_WORKER = 25;

    protected function setUp(): void
    {
        // NubitSequenceBundle decorates the API Platform bridge's documentation
        // normalizer, which NubitAdminBundle is what registers in an application.
        $this->boot([NubitAdminBundle::class, NubitSequenceBundle::class], [
            'nubit_admin' => [
                'app_profile' => 'internal',
                'auth' => ['secret' => '%env(APP_SECRET)%'],
            ],
        ]);
        $this->resetSchema();
    }

    public function testParallelWorkersNeverReceiveTheSameNumberAndNeverSkipOne(): void
    {
        $results = $this->runWorkers(['scope-a']);

        $values = array_map(intval(...), $results['scope-a'] ?? []);
        sort($values);

        self::assertSame(
            range(1, self::WORKERS * self::PER_WORKER),
            $values,
            'Every allocation must be unique and the run must be gapless.',
        );
    }

    public function testScopesAreCountedIndependentlyUnderContention(): void
    {
        $results = $this->runWorkers(['scope-a', 'scope-b']);

        foreach (['scope-a', 'scope-b'] as $scope) {
            $values = array_map(intval(...), $results[$scope] ?? []);
            sort($values);

            self::assertSame(range(1, self::WORKERS * self::PER_WORKER), $values, $scope);
        }
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, list<string>> values handed out, grouped by scope
     */
    private function runWorkers(array $scopes): array
    {
        $script = __DIR__ . '/allocation-worker.php';
        $startAt = microtime(true) + 1.5;
        $processes = [];

        for ($i = 0; $i < self::WORKERS; ++$i) {
            $command = array_merge([
                PHP_BINARY,
                '-d',
                'memory_limit=256M',
                $script,
                self::databaseUrl(),
                (string) $startAt,
                (string) self::PER_WORKER,
                'invoice',
            ], $scopes);
            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            self::assertTrue(isset($pipes[1], $pipes[2]) && is_resource($pipes[1]) && is_resource($pipes[2]));
            $processes[] = [$process, $pipes];
        }

        $grouped = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(0, proc_close($process), 'A worker failed: ' . $stderr . $stdout);

            foreach (explode("\n", trim($stdout)) as $line) {
                [$scope, $value] = explode(' ', $line, 2);
                $grouped[$scope][] = $value;
            }
        }

        return $grouped;
    }
}
