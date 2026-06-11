<?php

declare(strict_types=1);

namespace Nubit\Platform\Messenger;

use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class TenantContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantConnectionSwitcherInterface $tenantConnectionSwitcher,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        /** @var TenantStamp|null $stamp */
        $stamp = $envelope->last(TenantStamp::class);
        if ($stamp !== null && $stamp->tenantName !== null && $stamp->tenantName !== '') {
            $this->tenantContext->setTenant(
                $stamp->tenantId,
                $stamp->tenantName,
                $stamp->tenantDomain,
                $stamp->requestId,
            );

            $this->tenantConnectionSwitcher->switchConnection($stamp->tenantName);
        }

        /** @var ActorStamp|null $actorStamp */
        $actorStamp = $envelope->last(ActorStamp::class);
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
}
