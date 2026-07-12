<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Switcher;

use Nubit\Platform\Exception\ServiceException;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantDescriptorRegistryInterface;
use Nubit\Platform\Tenant\Model\TenantDescriptor;
use Nubit\TenantBundle\Contract\TenantIsolationTargetProviderInterface;
use Nubit\TenantBundle\Contract\TenantSchemaConnectionSwitcherInterface;
use Nubit\TenantBundle\Isolation\TenantIsolationTarget;
use Nubit\TenantBundle\Switcher\TenantRoutingConnectionSwitcher;
use Nubit\TenantBundle\Tests\Switcher\Fixture\RecordingDatabaseConnectionSwitcher;
use PHPUnit\Framework\TestCase;

final class TenantRoutingConnectionSwitcherTest extends TestCase
{
    public function testSchemaRouteResolvesTenantIdThenResetsSearchPath(): void
    {
        $registry = $this->createMock(TenantDescriptorRegistryInterface::class);
        $registry->expects(self::once())->method('findByName')->with('acme')->willReturn(new TenantDescriptor(42, 'acme'));
        $schema = $this->createMock(TenantSchemaConnectionSwitcherInterface::class);
        $schema->expects(self::once())->method('switchToTenantId')->with(42);
        $schema->expects(self::once())->method('resetSearchPath');

        $switcher = new TenantRoutingConnectionSwitcher('schema', $registry, $schema);
        $switcher->switchConnection('acme');
        $switcher->resetConnection();
    }

    public function testHybridRoutesDatabaseTargetAndResetsDynamicConnection(): void
    {
        $registry = $this->registry();
        $targets = $this->createMock(TenantIsolationTargetProviderInterface::class);
        $targets->expects(self::once())->method('resolveTarget')->with('acme', 42)
            ->willReturn(new TenantIsolationTarget(TenantIsolationTarget::DATABASE, 'pgsql://tenant'));
        $database = new RecordingDatabaseConnectionSwitcher();

        $switcher = new TenantRoutingConnectionSwitcher('hybrid', $registry, null, $targets, $database);
        $switcher->switchConnection('acme');
        $switcher->resetConnection();

        static::assertSame(['pgsql://tenant'], $database->urls);
        static::assertSame(1, $database->resets);
    }

    public function testHybridRoutesSchemaAndColumnTargets(): void
    {
        $schema = $this->createMock(TenantSchemaConnectionSwitcherInterface::class);
        $schema->expects(self::once())->method('switchToTenantId')->with(42);
        $schema->expects(self::once())->method('resetSearchPath');
        $column = $this->createMock(TenantConnectionSwitcherInterface::class);
        $column->expects(self::once())->method('switchConnection')->with('column-tenant');
        $targets = $this->createMock(TenantIsolationTargetProviderInterface::class);
        $targets->expects(self::exactly(2))->method('resolveTarget')->willReturnCallback(
            static fn (string $name): TenantIsolationTarget => new TenantIsolationTarget(
                'acme' === $name ? TenantIsolationTarget::SCHEMA : TenantIsolationTarget::COLUMN,
            ),
        );

        $switcher = new TenantRoutingConnectionSwitcher('hybrid', $this->registry(), $schema, $targets, null, $column);
        $switcher->switchConnection('acme');
        $switcher->resetConnection();
        $switcher->switchConnection('column-tenant');
        $switcher->resetConnection();
    }

    public function testSchemaRouteFailsClosedWhenTenantCannotBeResolved(): void
    {
        $registry = $this->createStub(TenantDescriptorRegistryInterface::class);
        $registry->method('findByName')->willReturn(null);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('No tenant descriptor found');

        (new TenantRoutingConnectionSwitcher('schema', $registry, $this->createStub(TenantSchemaConnectionSwitcherInterface::class)))
            ->switchConnection('missing');
    }

    private function registry(): TenantDescriptorRegistryInterface
    {
        $registry = $this->createStub(TenantDescriptorRegistryInterface::class);
        $registry->method('findByName')->willReturnCallback(
            static fn (string $name): TenantDescriptor => new TenantDescriptor(42, $name),
        );

        return $registry;
    }
}
