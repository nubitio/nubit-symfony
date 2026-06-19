<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Quota;

use Nubit\Platform\Exception\QuotaExceededException;
use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;
use Nubit\Platform\Quota\Contract\QuotaEnforcerInterface;
use Nubit\TenantBundle\Contract\QuotaUsageProviderInterface;

/**
 * Enforces plan limits from feature entitlements ({@code config.max}) against live usage counts.
 */
final readonly class FeatureQuotaEnforcer implements QuotaEnforcerInterface
{
    public function __construct(
        private FeatureCheckerInterface $featureChecker,
        private QuotaUsageProviderInterface $usageProvider,
    ) {
    }

    public function enforce(string $resource): void
    {
        if (!$this->featureChecker->hasFeature($resource)) {
            return;
        }

        $config = $this->featureChecker->getFeatureConfig($resource);
        $max = $config['max'] ?? null;
        if (!is_int($max) && !is_numeric($max)) {
            return;
        }

        $limit = (int) $max;
        if ($limit < 0) {
            return;
        }

        $current = $this->usageProvider->count($resource);
        if ($current >= $limit) {
            throw new QuotaExceededException($resource, $current, $limit);
        }
    }

    public function releaseLocks(): void
    {
    }
}