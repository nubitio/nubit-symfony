<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Command;

use Nubit\Platform\Tenant\Contract\TenantBackupRunnerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'nubit:tenant:backup',
    description: 'Run a database backup for one tenant via the configured TenantBackupRunnerInterface.',
)]
final class TenantBackupCommand extends Command
{
    public function __construct(
        private readonly TenantBackupRunnerInterface $backupRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant', InputArgument::REQUIRED, 'Tenant name to back up.')
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Backup type label (e.g. full, incremental).',
                'full',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Run pg_dump but discard the result instead of persisting it.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $tenant */
        $tenant = $input->getArgument('tenant');
        /** @var string $type */
        $type = $input->getOption('type');
        $persist = !$input->getOption('dry-run');

        try {
            $result = $this->backupRunner->backup($tenant, $persist, $type);
        } catch (\Throwable $e) {
            $io->error(sprintf('Backup failed for tenant "%s": %s', $tenant, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Backed up tenant "%s" → %s (%s, %s bytes, %s)',
            $tenant,
            $result['storage_path'],
            $result['filename'],
            number_format($result['size_bytes']),
            $result['storage_type'],
        ));

        return Command::SUCCESS;
    }
}
