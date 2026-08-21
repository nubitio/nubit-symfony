<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Symfony\Console;

use Nubit\Platform\Symfony\Console\PerTenantCommand;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantRegistryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PerTenantCommandTest extends TestCase
{
    public function testExecuteForSingleTenantActivatesRuntimeAndClearsContext(): void
    {
        $context = new TenantContext();
        $switcher = new RecordingTenantConnectionSwitcher();
        $command = new RecordingPerTenantCommand($context);
        $command->setName('app:test-tenants');
        $command->initDependencies(
            new StaticTenantRegistry([
                ['id' => 7, 'name' => 'acme', 'primary_domain' => 'acme.example.test'],
            ]),
            $switcher,
            $context,
            new NullLogger(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--tenant' => 'acme']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['acme'], $switcher->tenants);
        self::assertSame(
            [
                'tenantId' => 7,
                'tenantName' => 'acme',
                'tenantDomain' => 'acme.example.test',
                'actorIdentifier' => 'cli:app:test-tenants',
                'channel' => 'cli',
                'commandName' => 'app:test-tenants',
                'currentTenantName' => 'acme',
            ],
            $command->capturedContext,
        );
        self::assertNull($context->getTenantId());
        self::assertNull($context->getTenantName());
        self::assertNull($context->getActorIdentifier());
    }
}

/** @internal */
final class RecordingPerTenantCommand extends PerTenantCommand
{
    /** @var array<string, mixed> */
    public array $capturedContext = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    protected function executeTenantCommand(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output,
    ): int {
        unset($input, $output);

        $this->capturedContext = [
            'tenantId' => $this->tenantContext->getTenantId(),
            'tenantName' => $this->tenantContext->getTenantName(),
            'tenantDomain' => $this->tenantContext->getTenantDomain(),
            'actorIdentifier' => $this->tenantContext->getActorIdentifier(),
            'channel' => $this->tenantContext->getChannel(),
            'commandName' => $this->tenantContext->getCommandName(),
            'currentTenantName' => $this->currentTenantName,
        ];

        return Command::SUCCESS;
    }
}

/** @internal */
final readonly class StaticTenantRegistry implements TenantRegistryInterface
{
    /**
     * @param array<int, array<string, mixed>> $tenants
     */
    public function __construct(
        private array $tenants,
    ) {}

    public function getTenants(): array
    {
        return $this->tenants;
    }

    public function getTenantByName(string $name): ?array
    {
        foreach ($this->tenants as $tenant) {
            if (($tenant['name'] ?? null) === $name) {
                return $tenant;
            }
        }

        return null;
    }

    public function getTenantByDomain(string $domain): ?array
    {
        foreach ($this->tenants as $tenant) {
            if (($tenant['primary_domain'] ?? null) === $domain) {
                return $tenant;
            }
        }

        return null;
    }
}

/** @internal */
final class RecordingTenantConnectionSwitcher implements TenantConnectionSwitcherInterface
{
    /** @var list<string> */
    public array $tenants = [];

    public function switchConnection(string $tenant): void
    {
        $this->tenants[] = $tenant;
    }
}
