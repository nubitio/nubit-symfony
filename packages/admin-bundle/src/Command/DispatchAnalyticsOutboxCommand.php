<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Analytics\Entity\AnalyticsOutboxEntry;
use Nubit\AdminBundle\Analytics\Message\DeliverAnalyticsOutbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'nubit:analytics:dispatch-outbox', description: 'Dispatch due analytics outbox entries.')]
final class DispatchAnalyticsOutboxCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly int $batchSize = 100,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum rows to dispatch.',
            (string) $this->batchSize,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, min(1000, (int) $input->getOption('limit')));
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('o.id')
            ->from(AnalyticsOutboxEntry::class, 'o')
            ->where('o.deliveredAt IS NULL')
            ->andWhere('o.nextAttemptAt <= :now')
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        foreach ($rows as $row) {
            $this->messageBus->dispatch(new DeliverAnalyticsOutbox((int) $row['id']));
        }

        $output->writeln(sprintf(
            'Dispatched %d analytics outbox entr%s.',
            count($rows),
            1 === count($rows) ? 'y' : 'ies',
        ));

        return Command::SUCCESS;
    }
}
