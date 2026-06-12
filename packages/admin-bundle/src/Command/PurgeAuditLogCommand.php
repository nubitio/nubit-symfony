<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Command;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Audit\Entity\AuditLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes audit entries older than the retention window. Schedule it — the
 * log grows with every audited write.
 */
#[AsCommand(
    name: 'nubit:audit:purge',
    description: 'Remove audit-trail entries older than the retention window.',
)]
final class PurgeAuditLogCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Override the configured retention window (days).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = $input->getOption('days') !== null ? (int) $input->getOption('days') : $this->retentionDays;
        $cutoff = new DateTimeImmutable()->sub(new DateInterval(sprintf('P%dD', max(0, $days))));

        $removed = $this->entityManager->createQueryBuilder()
            ->delete(AuditLog::class, 'a')
            ->where('a.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        $io->success(sprintf('Purged %d audit entrie(s) older than %s.', (int) $removed, $cutoff->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
