<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Command;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists every resource, route, and attribute-driven feature the Nubit stack
 * discovered from Doctrine entities — the backend counterpart to Nubit DevTools.
 */
#[AsCommand(
    name: 'nubit:discover',
    description: 'List API Platform resources, embedded-lines routes, and optional sequence/workflow features.',
)]
final class DiscoverCommand extends Command
{
    public function __construct(
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadata,
        private readonly EmbeddedLinesRegistry $embeddedLines,
        #[Autowire(service: 'service_container')]
        private readonly ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Nubit discovery');

        $this->renderApiResources($io);
        $this->renderEmbeddedLines($io);
        $this->renderSequences($io);
        $this->renderWorkflows($io);

        $io->note('Frontend contract: GET /api/docs.jsonld (x-crud on hydra:supportedProperty)');
        $io->note('Grid query protocol: packages/api-platform/contracts/x-grid-protocol.json');

        return Command::SUCCESS;
    }

    private function renderApiResources(SymfonyStyle $io): void
    {
        $rows = [];

        foreach ($this->resourceNames->create() as $resourceClass) {
            $metadata = $this->resourceMetadata->create($resourceClass);
            $shortName = $this->shortClassName($resourceClass);
            $collectionPath = null;
            $operations = [];

            foreach ($metadata as $resource) {
                foreach ($resource->getOperations() as $operation) {
                    $method = $operation->getMethod() ?? '?';
                    $operations[] = $method;
                    if ($operation instanceof GetCollection && $collectionPath === null) {
                        $collectionPath = $operation->getUriTemplate();
                    }
                }
            }

            $rows[] = [
                $shortName,
                $resourceClass,
                $collectionPath ?? '—',
                implode(', ', array_unique($operations)),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $io->section('API Platform resources (' . \count($rows) . ')');
        $io->table(['Short name', 'Class', 'Collection URL', 'Operations'], $rows);
    }

    private function renderEmbeddedLines(SymfonyStyle $io): void
    {
        $rows = [];

        foreach ($this->embeddedLines->all() as $definition) {
            $rows[] = [
                $this->shortClassName($definition->entityClass),
                $definition->routePath,
                $definition->parentProperty,
                $definition->routeName,
            ];
        }

        $io->section('Embedded lines (' . \count($rows) . ')');
        if ($rows === []) {
            $io->text('None — add #[EmbeddedLines] on line entities.');
        } else {
            $io->table(['Entity', 'Route', 'Parent property', 'Route name'], $rows);
        }
    }

    private function renderSequences(SymfonyStyle $io): void
    {
        if (!\class_exists('Nubit\SequenceBundle\Sequence\SequenceRegistry')
            || !$this->container->has('Nubit\SequenceBundle\Sequence\SequenceRegistry')) {
            $io->section('Sequences');
            $io->text('Sequence bundle not installed.');

            return;
        }

        $registry = $this->container->get('Nubit\SequenceBundle\Sequence\SequenceRegistry');
        $rows = [];

        foreach ($this->resourceNames->create() as $resourceClass) {
            $sequence = $registry->getByEntityClass($resourceClass);
            if (null === $sequence) {
                continue;
            }

            $rows[] = [
                $this->shortClassName($resourceClass),
                $sequence->field,
                $sequence->prefix,
                (string) $sequence->padding,
            ];
        }

        $io->section('Sequences (' . \count($rows) . ')');
        if ($rows === []) {
            $io->text('None — add #[Sequence] on document entities.');
        } else {
            $io->table(['Entity', 'Field', 'Prefix', 'Padding'], $rows);
        }
    }

    private function renderWorkflows(SymfonyStyle $io): void
    {
        if (!\class_exists('Nubit\WorkflowBundle\Workflow\WorkflowRegistry')
            || !$this->container->has('Nubit\WorkflowBundle\Workflow\WorkflowRegistry')) {
            $io->section('Workflows');
            $io->text('Workflow bundle not installed.');

            return;
        }

        $registry = $this->container->get('Nubit\WorkflowBundle\Workflow\WorkflowRegistry');
        $rows = [];

        foreach ($registry->all() as $definition) {
            $rows[] = [
                $this->shortClassName($definition->entityClass),
                $definition->routePrefix,
                implode(', ', array_map(static fn ($t) => $t->name, $definition->transitions)),
            ];
        }

        $io->section('Workflows (' . \count($rows) . ')');
        if ($rows === []) {
            $io->text('None — add #[Workflow] on stateful resources.');
        } else {
            $io->table(['Entity', 'Route prefix', 'Transitions'], $rows);
        }
    }

    private function shortClassName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return false === $pos ? $class : substr($class, $pos + 1);
    }
}