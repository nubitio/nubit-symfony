<?php

declare(strict_types=1);

namespace Nubit\Platform\FeatureFlag;

use Nubit\Platform\FeatureFlag\Contract\FeatureFlagProviderInterface;
use Nubit\Platform\Tenant\Context\TenantContext;

final readonly class TenantFeatureFlags
{
    public function __construct(
        private FeatureFlagProviderInterface $provider,
        private TenantContext $tenantContext,
    ) {}

    public function boolean(string $key, bool $default = false): bool
    {
        return $this->provider->boolean($key, $default, $this->context());
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->provider->string($key, $default, $this->context());
    }

    public function integer(string $key, int $default = 0): int
    {
        return $this->provider->integer($key, $default, $this->context());
    }

    public function float(string $key, float $default = 0.0): float
    {
        return $this->provider->float($key, $default, $this->context());
    }

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function object(string $key, array $default = []): array
    {
        return $this->provider->object($key, $default, $this->context());
    }

    private function context(): FeatureFlagContext
    {
        $tenantId = $this->tenantContext->getTenantId();

        return new FeatureFlagContext(
            targetingKey: null !== $tenantId ? (string) $tenantId : null,
            tenantId: $tenantId,
            tenantName: $this->tenantContext->getTenantName(),
            attributes: ['tenant_domain' => $this->tenantContext->getTenantDomain()],
        );
    }
}
