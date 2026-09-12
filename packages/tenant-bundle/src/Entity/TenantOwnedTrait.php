<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Entity;

use Nubit\Platform\Tenant\Entity\TenantOwnedTrait as PlatformTenantOwnedTrait;

/**
 * The canonical implementation moved to {@see PlatformTenantOwnedTrait} in
 * `nubitio/platform`. This one only composes it, kept so existing code
 * `use`-ing this trait by name keeps working unchanged. Use the platform
 * trait directly in new code.
 */
trait TenantOwnedTrait
{
    use PlatformTenantOwnedTrait;
}
