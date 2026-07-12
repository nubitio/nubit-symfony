<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\ResettableTenantConnectionSwitcherInterface;
use Nubit\TenantBundle\Contract\TenantDatabaseConnectionSwitcherInterface;
use Nubit\TenantBundle\Contract\TenantIsolationTargetProviderInterface;
use Nubit\TenantBundle\Contract\TenantSchemaConnectionSwitcherInterface;
use Nubit\TenantBundle\Doctrine\Filter\TenantFilter;
use Nubit\TenantBundle\EventListener\TenantRequestListener;
use Nubit\TenantBundle\Isolation\TenantIsolationTarget;
use Nubit\TenantBundle\Resolver\ResolvedTenant;
use Nubit\TenantBundle\Resolver\TenantResolverInterface;
use Nubit\TenantBundle\Tests\EventListener\Fixture\RecordingFilterCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TenantRequestListenerTest extends TestCase
{
    public function testHybridDatabaseTargetSwitchesResolvedUrlWithoutEnablingColumnFilter(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedTenant(42, 'acme'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getFilters');
        $targetProvider = $this->createMock(TenantIsolationTargetProviderInterface::class);
        $targetProvider->expects(self::once())->method('resolveTarget')
            ->with('acme', 42)
            ->willReturn(new TenantIsolationTarget(TenantIsolationTarget::DATABASE, 'postgresql://acme'));
        $databaseSwitcher = $this->createMock(TenantDatabaseConnectionSwitcherInterface::class);
        $databaseSwitcher->expects(self::once())->method('switchToDatabaseUrl')->with('postgresql://acme');

        $listener = $this->listener($resolver, $entityManager, $targetProvider, $databaseSwitcher);
        $listener($this->requestEvent());
    }

    public function testHybridColumnTargetEnablesTheExistingColumnFilter(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedTenant(42, 'acme'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $value): string => "'{$value}'");
        $entityManager->method('getConnection')->willReturn($connection);
        $filters = new RecordingFilterCollection();
        $filter = new TenantFilter($entityManager);
        $filters->filter = $filter;
        $entityManager->method('getFilters')->willReturn($filters);
        $targetProvider = $this->createMock(TenantIsolationTargetProviderInterface::class);
        $targetProvider->method('resolveTarget')->willReturn(new TenantIsolationTarget(TenantIsolationTarget::COLUMN));

        $listener = $this->listener($resolver, $entityManager, $targetProvider, $this->createMock(TenantDatabaseConnectionSwitcherInterface::class));
        $listener($this->requestEvent());

        static::assertTrue($filters->enabled);
        static::assertTrue($filter->hasParameter(TenantFilter::PARAMETER));
    }

    public function testHybridSchemaTargetUsesResolvedIdAndResetsAfterResponse(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedTenant(42, 'untrusted-slug'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getFilters');
        $provider = $this->createMock(TenantIsolationTargetProviderInterface::class);
        $provider->method('resolveTarget')->willReturn(new TenantIsolationTarget(TenantIsolationTarget::SCHEMA));
        $schemaSwitcher = $this->createMock(TenantSchemaConnectionSwitcherInterface::class);
        $schemaSwitcher->expects(self::once())->method('switchToTenantId')->with(42);
        $schemaSwitcher->expects(self::once())->method('resetSearchPath');

        $listener = $this->listener($resolver, $entityManager, $provider, $this->createMock(TenantDatabaseConnectionSwitcherInterface::class), $schemaSwitcher);
        $listener($this->requestEvent());
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new \Symfony\Component\HttpFoundation\Response()));
    }

    public function testUpdatesTenantFilterParametersWhenTheLongLivedFilterIsAlreadyEnabled(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedTenant(42, 'acme'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $value): string => "'{$value}'");
        $entityManager->method('getConnection')->willReturn($connection);
        $filters = new RecordingFilterCollection();
        $filters->filter = new TenantFilter($entityManager);
        $filters->enabled = true;
        $filters->filter->setParameter(TenantFilter::PARAMETER, 7, 'integer');
        $entityManager->method('getFilters')->willReturn($filters);
        $provider = $this->createMock(TenantIsolationTargetProviderInterface::class);
        $provider->method('resolveTarget')->willReturn(new TenantIsolationTarget(TenantIsolationTarget::COLUMN));

        $this->listener($resolver, $entityManager, $provider, $this->createMock(TenantDatabaseConnectionSwitcherInterface::class))($this->requestEvent());

        static::assertSame('42', trim($filters->filter->getParameter(TenantFilter::PARAMETER), "'"));
    }

    public function testHybridDatabaseTargetResetsSwitchableConnectionAfterResponse(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedTenant(42, 'acme'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $provider = $this->createMock(TenantIsolationTargetProviderInterface::class);
        $provider->method('resolveTarget')->willReturn(new TenantIsolationTarget(TenantIsolationTarget::DATABASE, 'postgresql://acme'));
        $switcher = new class implements TenantDatabaseConnectionSwitcherInterface, ResettableTenantConnectionSwitcherInterface {
            public int $resets = 0;

            public function switchToDatabaseUrl(string $databaseUrl): void
            {
            }

            public function resetConnection(): void
            {
                ++$this->resets;
            }
        };

        $listener = $this->listener($resolver, $entityManager, $provider, $switcher);
        $listener($this->requestEvent());
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new \Symfony\Component\HttpFoundation\Response()));

        static::assertSame(1, $switcher->resets);
    }

    private function listener(
        TenantResolverInterface $resolver,
        EntityManagerInterface $entityManager,
        TenantIsolationTargetProviderInterface $targetProvider,
        TenantDatabaseConnectionSwitcherInterface $databaseSwitcher,
        ?TenantSchemaConnectionSwitcherInterface $schemaSwitcher = null,
    ): TenantRequestListener {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        return new TenantRequestListener(
            $resolver,
            new TenantContext(),
            $entityManager,
            $security,
            $this->createMock(TenantConnectionSwitcherInterface::class),
            'hybrid',
            false,
            null,
            [],
            $targetProvider,
            $databaseSwitcher,
            $schemaSwitcher,
        );
    }

    private function requestEvent(): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST);
    }
}
