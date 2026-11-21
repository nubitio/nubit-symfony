<?php

declare(strict_types=1);

namespace Nubit\Platform\Symfony\Console;

use Exception;
use Nubit\Platform\Exception\ServiceException;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Nubit\Platform\Tenant\Model\TenantDescriptor;
use Nubit\Platform\Tenant\Runtime\TenantRuntime;
use Nubit\Platform\Tenant\Runtime\TenantRuntimeActor;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Service\Attribute\Required;

abstract class PerTenantCommand extends Command
{
    use LockableTrait;

    private TenantRegistryInterface $tenantManager;
    private TenantRuntime $tenantRuntime;
    protected LoggerInterface $logger;

    /** @var array<int, array<string, mixed>> */
    protected array $tenants = [];
    protected ?string $currentTenantName = null;

    #[Required]
    public function initDependencies(
        TenantRegistryInterface $tenantManager,
        TenantConnectionSwitcherInterface $tenantConnectionSwitcher,
        TenantContext $tenantContext,
        LoggerInterface $logger,
        ?TenantRuntime $tenantRuntime = null,
    ): void {
        $this->tenantManager = $tenantManager;
        $this->tenantRuntime = $tenantRuntime ?? new TenantRuntime($tenantConnectionSwitcher, $tenantContext);
        $this->logger = $logger;
    }

    #[Override]
    protected function configure(): void
    {
        $this->setHelp('This command allows you to execute tenant command');
        $this->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Execute command for a specific tenant');
        $this->addOption(
            'parallel',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Run for all tenants in parallel (value = max concurrency, default 4)',
            false,
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tenants = $this->tenantManager->getTenants();

        if (!$this->lock()) {
            $this->logger->info('The command is already running in another process.');
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);

        try {
            if ($this->tenants === []) {
                $this->logger->info('No tenants provisioned — nothing to do.');
                $io->note('No tenants provisioned — nothing to do.');

                return Command::SUCCESS;
            }

            if ($tenantName = $input->getOption('tenant')) {
                $tenant = $this->tenantManager->getTenantByName($tenantName);
                if ($tenant === null) {
                    $io->error(sprintf('Tenant "%s" not found.', $tenantName));

                    return Command::FAILURE;
                }

                $result = $this->executeForTenant(
                    $tenant,
                    $input,
                    $output,
                    static function (TenantDescriptor $descriptor) use ($io): void {
                        $io->title('Tenant: ' . $descriptor->name);
                    },
                );
                if ($result === Command::SUCCESS) {
                    $io->success('Done!');
                }

                return $result;
            }

            $parallel = $input->getOption('parallel');
            if ($parallel !== false) {
                $concurrency = (int) ($parallel ?: 4);
                if ($concurrency < 1) {
                    $io->warning('Invalid parallel value; using 1.');
                    $concurrency = 1;
                }

                return $this->executeAllTenantsParallel($io, $input, $concurrency);
            }

            return $this->executeAllTenants($io, $input, $output);
        } finally {
            $this->release();
        }
    }

    private function executeAllTenants(SymfonyStyle $io, InputInterface $input, OutputInterface $output): int
    {
        $failedTenants = [];

        foreach ($this->tenants as $tenant) {
            try {
                $result = $this->executeForTenant(
                    $tenant,
                    $input,
                    $output,
                    static function (TenantDescriptor $descriptor) use ($io): void {
                        $io->title('Tenant: ' . $descriptor->name);
                    },
                );
                if ($result !== Command::SUCCESS) {
                    $failedTenants[] = (string) $tenant['name'];
                }
            } catch (ServiceException|\Doctrine\DBAL\Driver\Exception|\Doctrine\DBAL\Exception|Exception $e) {
                $this->logger->error('An error occurred while executing command for tenant ' . $tenant['name'], [
                    'exception' => $e,
                ]);
                $io->error('An error occurred while executing command for tenant ' . $tenant['name']);
                $failedTenants[] = (string) $tenant['name'];
            }
        }

        if ($failedTenants !== []) {
            $io->error('Failed tenants: ' . implode(', ', array_unique($failedTenants)));

            return Command::FAILURE;
        }

        $io->success('Done!');

        return Command::SUCCESS;
    }

    private function executeAllTenantsParallel(SymfonyStyle $io, InputInterface $input, int $concurrency): int
    {
        /** @var string $commandName */
        $commandName = $this->getName();
        $io->info(sprintf(
            'Running "%s" for %d tenants (concurrency: %d)',
            $commandName,
            count($this->tenants),
            $concurrency,
        ));

        /** @var array<string, Process> $running */
        $running = [];
        $queue = array_map(static fn(array $t): string => (string) $t['name'], $this->tenants);
        $failed = [];
        $succeeded = [];

        while ($queue !== [] || $running !== []) {
            while (count($running) < $concurrency && $queue !== []) {
                // The enclosing `while` guarantees a non-empty queue, so this is always a string.
                $tenantName = array_shift($queue);

                $process = new Process($this->buildParallelCommandArgs($input, $commandName, $tenantName));
                $process->setTimeout(null);
                $process->start();
                $running[$tenantName] = $process;
                $io->writeln(sprintf('  <info>Started</info> %s', $tenantName));
            }

            foreach ($running as $tenantName => $process) {
                if (!$process->isRunning()) {
                    if ($process->isSuccessful()) {
                        $succeeded[] = $tenantName;
                        $io->writeln(sprintf('  <fg=green>Done</> %s', $tenantName));
                    } else {
                        $failed[] = $tenantName;
                        $this->logger->error('Parallel execution failed for tenant', [
                            'tenant' => $tenantName,
                            'command' => $process->getCommandLine(),
                            'output' => $process->getErrorOutput(),
                            'stdout' => $process->getOutput(),
                        ]);
                        $io->writeln(sprintf('  <fg=red>Failed</> %s', $tenantName));
                    }

                    unset($running[$tenantName]);
                }
            }

            if ($running !== []) {
                usleep(100_000);
            }
        }

        $io->newLine();
        $io->writeln(sprintf('Succeeded: %d, Failed: %d', count($succeeded), count($failed)));

        if ($failed !== []) {
            $io->error('Failed tenants: ' . implode(', ', $failed));

            return Command::FAILURE;
        }

        $io->success('All tenants processed');

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function buildParallelCommandArgs(InputInterface $input, string $commandName, string $tenantName): array
    {
        $args = [PHP_BINARY, 'bin/console', $commandName, '--tenant=' . $tenantName];

        foreach ($this->getDefinition()->getArguments() as $argumentName => $argument) {
            if ($argumentName === 'command') {
                continue;
            }

            $value = $input->getArgument($argumentName);
            if ($value === null) {
                continue;
            }

            if ($argument->isArray() && is_array($value)) {
                foreach ($value as $entry) {
                    $args[] = (string) $entry;
                }

                continue;
            }

            $args[] = (string) $value;
        }

        foreach ($this->getDefinition()->getOptions() as $optionName => $option) {
            if (in_array($optionName, ['tenant', 'parallel'], true)) {
                continue;
            }

            $value = $input->getOption($optionName);
            if ($value === null || $value === false || $value === []) {
                continue;
            }

            if ($option->acceptValue()) {
                if (is_array($value)) {
                    foreach ($value as $entry) {
                        $args[] = sprintf('--%s=%s', $optionName, (string) $entry);
                    }

                    continue;
                }

                $args[] = sprintf('--%s=%s', $optionName, (string) $value);

                continue;
            }

            if ($value === true) {
                $args[] = '--' . $optionName;
            }
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $tenant
     * @param callable(TenantDescriptor): void $beforeExecute
     */
    private function executeForTenant(
        array $tenant,
        InputInterface $input,
        OutputInterface $output,
        callable $beforeExecute,
    ): int {
        return $this->tenantRuntime->run(
            $tenant,
            function (TenantDescriptor $descriptor) use ($input, $output, $beforeExecute): int {
                $this->currentTenantName = $descriptor->name;
                $beforeExecute($descriptor);

                return $this->executeTenantCommand($input, $output);
            },
            new TenantRuntimeActor(
                actorIdentifier: $this->getName() !== null ? 'cli:' . $this->getName() : 'cli',
                channel: 'cli',
                commandName: $this->getName(),
            ),
        );
    }

    abstract protected function executeTenantCommand(InputInterface $input, OutputInterface $output): int;
}
