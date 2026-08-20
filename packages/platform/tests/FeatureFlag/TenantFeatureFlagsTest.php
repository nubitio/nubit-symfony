<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\FeatureFlag;

use Nubit\Platform\FeatureFlag\Contract\FeatureFlagProviderInterface;
use Nubit\Platform\FeatureFlag\FeatureFlagContext;
use Nubit\Platform\FeatureFlag\TenantFeatureFlags;
use Nubit\Platform\Tenant\Context\TenantContext;
use PHPUnit\Framework\TestCase;

final class TenantFeatureFlagsTest extends TestCase
{
    public function testBuildsTenantAwareEvaluationContext(): void
    {
        $provider = $this->createMock(FeatureFlagProviderInterface::class);
        $provider->expects(self::once())->method('boolean')->with(
            'new-grid',
            false,
            self::callback(static fn (FeatureFlagContext $context): bool =>
                '42' === $context->targetingKey
                && 42 === $context->tenantId
                && 'acme' === $context->tenantName
                && 'acme.example.test' === $context->attributes['tenant_domain']),
        )->willReturn(true);
        $tenantContext = new TenantContext();
        $tenantContext->setTenant(42, 'acme', 'acme.example.test', 'req-42');

        self::assertTrue((new TenantFeatureFlags($provider, $tenantContext))->boolean('new-grid'));
    }
}
