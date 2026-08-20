<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Command;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Analytics\Entity\AnalyticsOutboxEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'nubit:analytics:purge-outbox',
    description: 'Remove delivered analytics outbox entries past retention.',
)]
final class PurgeAnalyticsOutboxCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly int $retentionDays = 30,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cutoff = (new DateTimeImmutable())->sub(new DateInterval(sprintf('P%dD', $this->retentionDays)));
        /** @var int|numeric-string $removed */
        $removed = $this->entityManager
            ->createQueryBuilder()
            ->delete(AnalyticsOutboxEntry::class, 'o')
            ->where('o.deliveredAt IS NOT NULL')
            ->andWhere('o.deliveredAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        $output->writeln(sprintf('Removed %d delivered analytics outbox entries.', (int) $removed));

        return Command::SUCCESS;
    }
}
