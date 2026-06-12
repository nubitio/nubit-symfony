<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Command;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\AdminBundle\Media\MediaStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Hard-deletes media rows soft-deleted longer ago than the retention window,
 * removing the stored files with them. Instant uploads orphan a file whenever
 * a user abandons the form after uploading — schedule this (cron/messenger)
 * to keep storage bounded.
 */
#[AsCommand(
    name: 'nubit:media:purge',
    description: 'Remove soft-deleted media (rows + stored files) past the retention window.',
)]
final class PurgeMediaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaStorage $storage,
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
            'Override the configured retention window (days since soft-delete).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = $input->getOption('days') !== null ? (int) $input->getOption('days') : $this->retentionDays;
        $cutoff = new DateTimeImmutable()->sub(new DateInterval(sprintf('P%dD', max(0, $days))));

        /** @var list<Media> $expired */
        $expired = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where('m.deletedAt IS NOT NULL')
            ->andWhere('m.deletedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        $removed = 0;
        $missingFiles = 0;

        foreach ($expired as $media) {
            try {
                $this->storage->delete($media);
            } catch (FilesystemException) {
                ++$missingFiles;
            }

            $this->entityManager->remove($media);
            ++$removed;
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Purged %d media item(s) soft-deleted before %s.%s',
            $removed,
            $cutoff->format('Y-m-d H:i:s'),
            $missingFiles > 0 ? sprintf(' %d file(s) were already missing from storage.', $missingFiles) : '',
        ));

        return Command::SUCCESS;
    }
}
