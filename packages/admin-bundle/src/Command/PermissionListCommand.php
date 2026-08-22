<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Command;

use Nubit\AdminBundle\Authorization\PermissionCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the permission catalogue.
 *
 * Whoever configures a role needs the exact strings, and reading them off the
 * source is how a typo becomes a permission that is never granted and never
 * noticed. `--json` exists because the other reader is an agent generating a
 * role fixture.
 */
#[AsCommand(name: 'nubit:permissions:list', description: 'List every permission derived from the API resources.')]
final class PermissionListCommand extends Command
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->catalog->all();

        if ($input->getOption('json')) {
            $payload = [];
            foreach ($catalog as $prefix => $resource) {
                $payload[$prefix] = [
                    'class' => $resource->resourceClass,
                    'permissions' => $resource->permissions,
                    'limited' => $resource->limited,
                ];
            }

            $output->writeln((string) json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $rows = [];

        foreach ($catalog as $prefix => $resource) {
            foreach ($resource->permissions as $permission) {
                $rows[] = [
                    $permission,
                    $prefix,
                    $resource->limitProperty($permission) ?? '',
                ];
            }
        }

        if ([] === $rows) {
            $io->warning('No API resources are published, so there are no permissions to derive.');

            return Command::SUCCESS;
        }

        $io->table(['Permission', 'Resource', 'Capped by'], $rows);
        $io->comment(sprintf('%d permissions across %d resources.', count($rows), count($catalog)));

        return Command::SUCCESS;
    }
}
