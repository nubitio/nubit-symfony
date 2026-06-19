<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Runtime;

/** Default noop provider — returns an empty object until the app aliases a real one. */
final class NullRuntimeConfigProvider implements RuntimeConfigProviderInterface
{
    public function getConfig(): array
    {
        return [];
    }
}