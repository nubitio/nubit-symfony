<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Command;

use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'nubit:tenant:list',
    description: 'List provisioned tenants from the registry',
)]
final class TenantListCommand extends Command
{
    public function __construct(
        private readonly TenantRegistryInterface $tenantRegistry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenants = $this->tenantRegistry->getTenants();

        if ($tenants === []) {
            $io->note('No tenants provisioned.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            $rows[] = [
                $tenant['id'] ?? '',
                $tenant['name'] ?? '',
                $tenant['primary_domain'] ?? '',
                $tenant['plan'] ?? '',
                $tenant['status'] ?? '',
            ];
        }

        $io->table(['ID', 'Name', 'Domain', 'Plan', 'Status'], $rows);

        return Command::SUCCESS;
    }
}