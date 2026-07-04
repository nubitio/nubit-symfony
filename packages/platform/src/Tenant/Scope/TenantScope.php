<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Scope;

use Nubit\Platform\Tenant\Context\TenantContext;

final readonly class TenantScope
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    public function tenantName(): ?string
    {
        return $this->tenantContext->getTenantName();
    }

    public function cacheKey(string $key): string
    {
        $tenantName = $this->tenantName();
        if ($tenantName === null) {
            return $key;
        }

        return 't.' . self::sanitize($tenantName) . '.' . ltrim($key, '.');
    }

    public function path(string $path): string
    {
        $path = ltrim($path, '/');
        $tenantName = $this->tenantName();
        if ($tenantName === null) {
            return $path;
        }

        return self::sanitize($tenantName) . '/' . $path;
    }

    public function rateLimitKey(string $tenantName, string $window): string
    {
        return self::rateLimitCacheKey($tenantName, $window);
    }

    public static function rateLimitCacheKey(string $tenantName, string $window): string
    {
        return 'tenant_rl.' . self::sanitize($tenantName) . '.' . $window;
    }

    public static function sanitize(string $tenantName): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_.-]/', '_', trim($tenantName));

        return $sanitized === null || $sanitized === '' ? 'unknown' : $sanitized;
    }
}
