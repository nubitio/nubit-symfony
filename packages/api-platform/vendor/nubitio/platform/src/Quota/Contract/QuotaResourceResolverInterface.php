<?php

declare(strict_types=1);

namespace Nubit\Platform\Quota\Contract;

/**
 * Resolves application events or subjects into stable quota resource names.
 *
 * This future-facing port lets app adapters own domain-specific resource
 * mapping while the Platform quota capability remains resource-name based.
 */
interface QuotaResourceResolverInterface
{
    public function supports(object $subject): bool;

    public function resolveResource(object $subject): ?string;
}
