<?php

declare(strict_types=1);

namespace Nubit\Platform\Symfony\Console;

use Nubit\Platform\Exception\ServiceException;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Exception;
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
    private TenantConnectionSwitcherInterface $tenantConnectionSwitcher;
    private TenantContext $tenantContext;
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
    ): void {
        $this->tenantManager = $tenantManager;
        $this->tenantConnectionSwitcher = $tenantConnectionSwitcher;
        $this->tenantContext = $tenantContext;
        $this->logger = $logger;
    }

    #[Override]
    protected function configure(): void
    {
        $this->setHelp('This command allows you to execute tenant command');
        $this->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Execute command for a specific tenant');
        $this->addOption('parallel', 'p', InputOption::VALUE_OPTIONAL, 'Run for all tenants in parallel (value = max concurrency, default 4)', false);
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

                $this->switchToTenant($tenant);
                $io->title('Tenant: ' . $tenantName);
                $result = $this->executeTenantCommand($input, $output);
                if ($result === Command::SUCCESS) {
                    $io->success('Done!');
                }

                return $result;
            }

            $parallel = $input->getOption('parallel');
            if ($parallel !== false) {
                $concurrency = (int)($parallel ?: 4);
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
                $this->switchToTenant($tenant);
                $io->title('Tenant: ' . $tenant['name']);

                $result = $this->executeTenantCommand($input, $output);
                if ($result !== Command::SUCCESS) {
                    $failedTenants[] = (string)$tenant['name'];
                }
            } catch (ServiceException | \Doctrine\DBAL\Driver\Exception | \Doctrine\DBAL\Exception | Exception $e) {
                $this->logger->error('An error occurred while executing command for tenant ' . $tenant['name'], ['exception' => $e]);
                $io->error('An error occurred while executing command for tenant ' . $tenant['name']);
                $failedTenants[] = (string)$tenant['name'];
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
        $io->info(sprintf('Running "%s" for %d tenants (concurrency: %d)', $commandName, count($this->tenants), $concurrency));

        /** @var array<string, Process> $running */
        $running = [];
        $queue = array_map(static fn (array $t): string => (string)$t['name'], $this->tenants);
        $failed = [];
        $succeeded = [];

        while ($queue !== [] || $running !== []) {
            while (count($running) < $concurrency && $queue !== []) {
                $tenantName = array_shift($queue);
                if ($tenantName == null) {
                    break;
                }

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
                    $args[] = (string)$entry;
                }

                continue;
            }

            $args[] = (string)$value;
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
                        $args[] = sprintf('--%s=%s', $optionName, (string)$entry);
                    }

                    continue;
                }

                $args[] = sprintf('--%s=%s', $optionName, (string)$value);

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
     *
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    private function switchToTenant(array $tenant): void
    {
        $tenantName = (string)$tenant['name'];

        $this->tenantConnectionSwitcher->switchConnection($tenantName);
        $this->tenantContext->setTenant(
            isset($tenant['id']) ? (int)$tenant['id'] : null,
            $tenantName,
            isset($tenant['primary_domain']) ? (string)$tenant['primary_domain'] : null,
            null,
        );
        $this->currentTenantName = $tenantName;
    }

    abstract protected function executeTenantCommand(InputInterface $input, OutputInterface $output): int;
}
