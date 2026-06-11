<?php

declare(strict_types=1);

namespace Nubit\Platform\Exception;

class QuotaExceededException extends ServiceException
{
    private const int HTTP_STATUS_TOO_MANY_REQUESTS = 429;

    public function __construct(
        public readonly string $resource,
        public readonly int $current,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf(
            'Quota exceeded for "%s": %d/%d',
            $resource,
            $current,
            $limit,
        ), self::HTTP_STATUS_TOO_MANY_REQUESTS);
    }
}
