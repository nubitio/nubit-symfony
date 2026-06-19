<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Nubit\Platform\Quota\Contract\QuotaEnforcerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\TenantBundle\Attribute\QuotaResource;
use Nubit\TenantBundle\EventListener\QuotaEnforcementListener;
use Nubit\TenantBundle\Quota\QuotaResourceRegistry;
use PHPUnit\Framework\TestCase;

final class QuotaEnforcementListenerTest extends TestCase
{
    public function testPrePersistSkipsWhenDisabled(): void
    {
        $enforcer = $this->createMock(QuotaEnforcerInterface::class);
        $enforcer->expects(self::never())->method('enforce');

        $listener = new QuotaEnforcementListener(
            $enforcer,
            $this->tenantContext(1),
            new QuotaResourceRegistry(),
            false,
        );

        $listener->prePersist($this->lifecycleArgs(new QuotaListenerEntityStub()));
    }

    public function testPrePersistSkipsWithoutTenant(): void
    {
        $enforcer = $this->createMock(QuotaEnforcerInterface::class);
        $enforcer->expects(self::never())->method('enforce');

        $listener = new QuotaEnforcementListener(
            $enforcer,
            new TenantContext(),
            new QuotaResourceRegistry(),
            true,
        );

        $listener->prePersist($this->lifecycleArgs(new QuotaListenerEntityStub()));
    }

    public function testPrePersistEnforcesResolvedResource(): void
    {
        $enforcer = $this->createMock(QuotaEnforcerInterface::class);
        $enforcer->expects(self::once())->method('enforce')->with('team_users');

        $listener = new QuotaEnforcementListener(
            $enforcer,
            $this->tenantContext(1),
            new QuotaResourceRegistry(),
            true,
        );

        $listener->prePersist($this->lifecycleArgs(new QuotaListenerEntityStub()));
    }

    public function testPostFlushReleasesLocks(): void
    {
        $enforcer = $this->createMock(QuotaEnforcerInterface::class);
        $enforcer->expects(self::once())->method('releaseLocks');

        $listener = new QuotaEnforcementListener(
            $enforcer,
            new TenantContext(),
            new QuotaResourceRegistry(),
            true,
        );

        $listener->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));
    }

    public function testOnClearReleasesLocks(): void
    {
        $enforcer = $this->createMock(QuotaEnforcerInterface::class);
        $enforcer->expects(self::once())->method('releaseLocks');

        $listener = new QuotaEnforcementListener(
            $enforcer,
            new TenantContext(),
            new QuotaResourceRegistry(),
            true,
        );

        $listener->onClear(new OnClearEventArgs($this->createStub(EntityManagerInterface::class)));
    }

    private function tenantContext(int $tenantId): TenantContext
    {
        $context = new TenantContext();
        $context->setTenant($tenantId, 'demo', null, null);

        return $context;
    }

    /** @return LifecycleEventArgs<EntityManagerInterface> */
    private function lifecycleArgs(object $entity): LifecycleEventArgs
    {
        $args = $this->createStub(LifecycleEventArgs::class);
        $args->method('getObject')->willReturn($entity);

        return $args;
    }
}

#[QuotaResource('team_users')]
final class QuotaListenerEntityStub
{
}