<?php

declare(strict_types=1);

namespace Nubit\Platform\Quota\Contract;

interface QuotaEnforcerInterface
{
    public function enforce(string $resource): void;

    public function releaseLocks(): void;
}
