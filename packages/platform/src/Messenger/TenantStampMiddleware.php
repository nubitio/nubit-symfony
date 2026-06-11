<?php

declare(strict_types=1);

namespace Nubit\Platform\Messenger;

use Nubit\Platform\Tenant\Context\TenantContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class TenantStampMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(TenantStamp::class) === null) {
            $envelope = $envelope->with(new TenantStamp(
                $this->tenantContext->getTenantId(),
                $this->tenantContext->getTenantName(),
                $this->tenantContext->getTenantDomain(),
                $this->tenantContext->getRequestId(),
            ));
        }

        if ($envelope->last(ActorStamp::class) === null) {
            $envelope = $envelope->with(new ActorStamp(
                $this->tenantContext->getActorIdentifier(),
                $this->tenantContext->getChannel(),
                $this->tenantContext->getCommandName(),
            ));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
