<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Command;

use Nubit\AdminBundle\Auth\RefreshTokenStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes expired and long-revoked refresh tokens. Schedule it daily — via
 * cron, or symfony/scheduler in the app:
 *
 *     #[AsCronTask('0 3 * * *')] on a wrapper, or `bin/console nubit:auth:purge-refresh-tokens`
 *
 * Multi-tenant apps should run it once per tenant connection.
 */
#[AsCommand(
    name: 'nubit:auth:purge-refresh-tokens',
    description: 'Deletes expired and long-revoked refresh tokens.',
)]
final class PurgeRefreshTokensCommand extends Command
{
    public function __construct(
        private readonly RefreshTokenStoreInterface $refreshTokenStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->refreshTokenStore->purgeExpired();

        (new SymfonyStyle($input, $output))->success(sprintf('%d refresh token(s) purged.', $count));

        return Command::SUCCESS;
    }
}
