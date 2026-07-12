<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Switcher\Fixture;

use Nubit\Platform\Tenant\Contract\ResettableTenantConnectionSwitcherInterface;
use Nubit\TenantBundle\Contract\TenantDatabaseConnectionSwitcherInterface;

/** @internal */
final class RecordingDatabaseConnectionSwitcher implements TenantDatabaseConnectionSwitcherInterface, ResettableTenantConnectionSwitcherInterface
{
    /** @var list<string> */
    public array $urls = [];

    public int $resets = 0;

    public function switchToDatabaseUrl(string $databaseUrl): void
    {
        $this->urls[] = $databaseUrl;
    }

    public function resetConnection(): void
    {
        ++$this->resets;
    }
}
