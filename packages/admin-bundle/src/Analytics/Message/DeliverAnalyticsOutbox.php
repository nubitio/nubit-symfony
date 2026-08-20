<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Analytics\Message;

final readonly class DeliverAnalyticsOutbox
{
    public function __construct(
        public int $entryId,
    ) {}
}
