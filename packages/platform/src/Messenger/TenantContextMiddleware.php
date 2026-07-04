<?php

declare(strict_types=1);

namespace Nubit\Platform\Messenger;

use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Model\TenantDescriptor;
use Nubit\Platform\Tenant\Runtime\TenantRuntime;
use Nubit\Platform\Tenant\Runtime\TenantRuntimeActor;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class TenantContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantConnectionSwitcherInterface $tenantConnectionSwitcher,
        private ?TenantRuntime $tenantRuntime = null,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        /** @var TenantStamp|null $stamp */
        $stamp = $envelope->last(TenantStamp::class);

        /** @var ActorStamp|null $actorStamp */
        $actorStamp = $envelope->last(ActorStamp::class);
        $runtimeActor = new TenantRuntimeActor(
            actorIdentifier: $actorStamp?->actorIdentifier,
            channel: 'messenger',
            commandName: $actorStamp?->commandName,
            requestId: $stamp?->requestId,
        );

        if ($stamp !== null && $stamp->tenantName !== null && $stamp->tenantName !== '' && $stamp->tenantId !== null) {
            $tenant = new TenantDescriptor(
                id: $stamp->tenantId,
                name: $stamp->tenantName,
                primaryDomain: $stamp->tenantDomain,
            );

            return $this->runtime()->run(
                $tenant,
                static fn () => $stack->next()->handle($envelope, $stack),
                $runtimeActor,
            );
        }

        if ($stamp !== null && $stamp->tenantName !== null && $stamp->tenantName !== '') {
            $this->tenantContext->setTenant(
                $stamp->tenantId,
                $stamp->tenantName,
                $stamp->tenantDomain,
                $stamp->requestId,
            );

            $this->tenantConnectionSwitcher->switchConnection($stamp->tenantName);
        }

        $this->tenantContext->setActor(
            $actorStamp?->actorIdentifier,
            'messenger',
            $actorStamp?->commandName,
        );

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function runtime(): TenantRuntime
    {
        return $this->tenantRuntime ?? new TenantRuntime($this->tenantConnectionSwitcher, $this->tenantContext);
    }
}
