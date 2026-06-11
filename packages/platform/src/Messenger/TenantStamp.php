<?php

declare(strict_types=1);

namespace Nubit\Platform\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class TenantStamp implements StampInterface
{
    public function __construct(
        public ?int $tenantId,
        public ?string $tenantName,
        public ?string $tenantDomain,
        public ?string $requestId,
    ) {
    }
}
