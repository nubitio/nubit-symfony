<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Runtime;

/**
 * Supplies the JSON body for {@code GET /api/runtime-config}. The core does
 * not fix a schema — each application defines the shape (UI flags, defaults,
 * capabilities, onboarding state, etc.). Alias your implementation over the
 * default {@see NullRuntimeConfigProvider}.
 */
interface RuntimeConfigProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array;
}