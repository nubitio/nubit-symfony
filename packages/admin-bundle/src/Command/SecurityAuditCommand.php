<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Command;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\AdminBundle\Security\UnguardedOperationScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Safety net for the RBAC model documented in `references/erp-and-permissions.md`:
 * every /api route requires ROLE_USER by default, and stricter access is
 * opt-in per operation via `security: "is_granted('ROLE_...')"`. Nothing
 * forces a developer to remember that on a new DELETE/PATCH/POST operation —
 * this command lists every write operation that never opted in, so an
 * unintentionally under-guarded endpoint shows up before it ships instead of
 * during an incident.
 */
#[AsCommand(
    name: 'nubit:security:audit',
    description: 'List write operations (POST/PUT/PATCH/DELETE) with no security: expression.',
)]
final class SecurityAuditCommand extends Command
{
    public function __construct(
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadata,
        private readonly UnguardedOperationScanner $scanner = new UnguardedOperationScanner(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'strict',
            null,
            InputOption::VALUE_NONE,
            'Exit with a non-zero status when any unguarded write operation is found (CI-friendly).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Nubit security audit');

        $findings = $this->scanner->scan($this->collectOperations());

        if ($findings === []) {
            $io->success('Every POST/PUT/PATCH/DELETE operation declares a security: expression.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Resource', 'Method', 'URI template'],
            array_map(
                static fn ($finding) => [$finding->resourceShortName, $finding->method, $finding->uriTemplate ?? '—'],
                $findings,
            ),
        );

        $io->note(sprintf(
            '%d write operation(s) rely on the default firewall access_control (ROLE_USER) with no per-operation '
                . 'role check. Add security: "is_granted(\'ROLE_...\')" if that\'s not intentional.',
            \count($findings),
        ));

        if ($input->getOption('strict')) {
            $io->error('Failing: --strict was set and unguarded write operations were found.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return iterable<array{resourceClass: string, operation: HttpOperation}>
     */
    private function collectOperations(): iterable
    {
        /** @var iterable<string> $resourceClasses */
        $resourceClasses = $this->resourceNames->create();

        foreach ($resourceClasses as $resourceClass) {
            foreach ($this->resourceMetadata->create($resourceClass) as $resource) {
                /** @var iterable<HttpOperation> $operations */
                $operations = $resource->getOperations() ?? [];
                foreach ($operations as $operation) {
                    yield ['resourceClass' => $resourceClass, 'operation' => $operation];
                }
            }
        }
    }
}
