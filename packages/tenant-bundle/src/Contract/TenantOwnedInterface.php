<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

use Nubit\Platform\Tenant\Contract\TenantOwnedInterface as PlatformTenantOwnedInterface;

/**
 * Rows owned by a tenant. Reads are scoped by {@see \Nubit\TenantBundle\Doctrine\Filter\TenantFilter};
 * writes are stamped by {@see \Nubit\TenantBundle\EventListener\TenantStampListener}.
 *
 * The canonical declaration moved to {@see PlatformTenantOwnedInterface} in
 * `nubitio/platform`, so a package can mark its entities tenant-owned without
 * requiring tenant-bundle at all. This one now only extends it, kept so
 * existing code naming this interface — and every check in this bundle
 * against the platform interface, which an entity declaring only this one
 * still satisfies — keeps working unchanged. Implement the platform interface
 * directly in new code.
 */
interface TenantOwnedInterface extends PlatformTenantOwnedInterface {}
