<?php

declare(strict_types=1);

namespace Nubit\Platform\Feature\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a tenant tries to access a feature not included in their plan.
 * HTTP 402 Payment Required — signals the client to prompt for an upgrade.
 */
final class FeatureNotAvailableException extends HttpException
{
    public function __construct(
        public readonly string $featureKey,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            402,
            $message ?: sprintf('Feature "%s" is not available on your current plan.', $featureKey),
            $previous,
            [],
            402,
        );
    }
}
