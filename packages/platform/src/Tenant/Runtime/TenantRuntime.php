<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Runtime;

use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\ResettableTenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Model\TenantDescriptor;

final readonly class TenantRuntime
{
    public function __construct(
        private TenantConnectionSwitcherInterface $connectionSwitcher,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param TenantDescriptor|array<string, mixed> $tenant
     */
    public function activate(TenantDescriptor|array $tenant, ?TenantRuntimeActor $actor = null): TenantDescriptor
    {
        $descriptor = $tenant instanceof TenantDescriptor ? $tenant : TenantDescriptor::fromArray($tenant);

        $this->connectionSwitcher->switchConnection($descriptor->name);
        $this->tenantContext->setTenant(
            $descriptor->id,
            $descriptor->name,
            $descriptor->primaryDomain,
            $actor?->requestId,
        );

        if ($actor !== null) {
            $this->tenantContext->setActor(
                $actor->actorIdentifier,
                $actor->channel,
                $actor->commandName,
            );
        }

        return $descriptor;
    }

    /**
     * @template T
     *
     * @param TenantDescriptor|array<string, mixed> $tenant
     * @param callable(TenantDescriptor): T $callback
     *
     * @return T
     */
    public function run(TenantDescriptor|array $tenant, callable $callback, ?TenantRuntimeActor $actor = null): mixed
    {
        $descriptor = $this->activate($tenant, $actor);

        try {
            return $callback($descriptor);
        } finally {
            $this->clear();
        }
    }

    public function clear(): void
    {
        try {
            if ($this->connectionSwitcher instanceof ResettableTenantConnectionSwitcherInterface) {
                $this->connectionSwitcher->resetConnection();
            }
        } finally {
            $this->tenantContext->clear();
        }
    }
}
