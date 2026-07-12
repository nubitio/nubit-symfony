<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Switcher;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use Nubit\Platform\Exception\ServiceException;
use Nubit\TenantBundle\Switcher\PostgresSchemaTenantConnectionSwitcher;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PostgresSchemaTenantConnectionSwitcherTest extends TestCase
{
    public function testBuildsSchemaFromPositiveTenantIdAndQuotesEveryIdentifier(): void
    {
        $connection = $this->connection(false);
        $connection->expects(self::once())->method('executeStatement')
            ->with('SET search_path TO "tenant_42", "public", "extensions"');

        $switcher = new PostgresSchemaTenantConnectionSwitcher($this->registry($connection), 'tenant', 'tenant_', ['public', 'extensions']);
        $switcher->switchToTenantId(42);

        self::assertSame('tenant_42', $switcher->schemaForTenantId(42));
    }

    public function testResetUsesConfiguredBaseSchemasInsteadOfReset(): void
    {
        $connection = $this->connection(false);
        $connection->expects(self::once())->method('executeStatement')
            ->with('SET search_path TO "public", "extensions"');

        (new PostgresSchemaTenantConnectionSwitcher($this->registry($connection), baseSchemas: ['public', 'extensions']))->resetSearchPath();
    }

    public function testRejectsSwitchAndResetDuringTransaction(): void
    {
        $connection = $this->connection(true);
        $connection->expects(self::never())->method('executeStatement');
        $switcher = new PostgresSchemaTenantConnectionSwitcher($this->registry($connection));

        foreach ([
            static fn () => $switcher->switchToTenantId(1),
            static fn () => $switcher->resetSearchPath(),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected active transaction rejection.');
            } catch (ServiceException $exception) {
                self::assertStringContainsString('active transaction', $exception->getMessage());
            }
        }
    }

    #[DataProvider('invalidIdentifiers')]
    public function testRejectsUnsafePrefixesAndInvalidFinalIdentifiers(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PostgresSchemaTenantConnectionSwitcher($this->registry($this->connection(false)), schemaPrefix: $prefix);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdentifiers(): iterable
    {
        yield 'uppercase' => ['Tenant_'];
        yield 'punctuation' => ['tenant-'];
        yield 'leading digit' => ['1tenant_'];
        yield 'too long' => [str_repeat('a', 64)];
    }

    public function testRejectsNonPositiveIdsAndFinalNamesOverPostgresLimit(): void
    {
        $switcher = new PostgresSchemaTenantConnectionSwitcher($this->registry($this->connection(false)), schemaPrefix: str_repeat('a', 63));

        try {
            $switcher->switchToTenantId(0);
            self::fail('Expected invalid tenant ID.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('positive', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $switcher->schemaForTenantId(1);
    }

    private function connection(bool $transactionActive): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn($transactionActive);

        return $connection;
    }

    private function registry(Connection $connection): ConnectionRegistry
    {
        $registry = $this->createMock(ConnectionRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        return $registry;
    }
}
