<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Runtime;

final readonly class TenantRuntimeActor
{
    public function __construct(
        public ?string $actorIdentifier = null,
        public ?string $channel = null,
        public ?string $commandName = null,
        public ?string $requestId = null,
    ) {}
}
