<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Quota;

use Nubit\TenantBundle\Contract\QuotaUsageProviderInterface;
use Nubit\Platform\Exception\ServiceException;

/**
 * @internal
 */
final readonly class CompositeQuotaUsageProvider implements QuotaUsageProviderInterface
{
    /** @param iterable<QuotaUsageProviderInterface> $providers */
    public function __construct(
        private iterable $providers,
    ) {
    }

    public function supports(string $resource): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($resource)) {
                return true;
            }
        }

        return false;
    }

    public function count(string $resource): int
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($resource)) {
                return $provider->count($resource);
            }
        }

        throw new ServiceException(sprintf('No quota usage provider registered for resource "%s".', $resource));
    }
}