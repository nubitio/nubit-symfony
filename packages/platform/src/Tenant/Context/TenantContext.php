<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Context;

class TenantContext
{
    private ?int $tenantId = null;
    private ?string $tenantName = null;
    private ?string $tenantDomain = null;
    private ?string $requestId = null;
    private ?string $actorIdentifier = null;
    private ?string $channel = null;
    private ?string $commandName = null;

    public function setTenant(
        ?int $tenantId,
        ?string $tenantName,
        ?string $tenantDomain,
        ?string $requestId,
    ): void {
        $this->tenantId = $tenantId;
        $this->tenantName = $tenantName;
        $this->tenantDomain = $tenantDomain;
        $this->requestId = $requestId;
    }

    public function setActor(
        ?string $actorIdentifier,
        ?string $channel,
        ?string $commandName = null,
    ): void {
        $this->actorIdentifier = $actorIdentifier;
        $this->channel = $channel;
        $this->commandName = $commandName;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenantName = null;
        $this->tenantDomain = null;
        $this->requestId = null;
        $this->actorIdentifier = null;
        $this->channel = null;
        $this->commandName = null;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function getTenantName(): ?string
    {
        return $this->tenantName;
    }

    public function getTenantDomain(): ?string
    {
        return $this->tenantDomain;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getActorIdentifier(): ?string
    {
        return $this->actorIdentifier;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function getCommandName(): ?string
    {
        return $this->commandName;
    }
}
