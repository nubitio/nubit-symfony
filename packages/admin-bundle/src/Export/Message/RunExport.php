<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export\Message;

/**
 * Carries only the job's identifier.
 *
 * The row filters are already durable on the job, so putting them in the
 * envelope would make the message a second source of truth that can drift from
 * the row while it waits in the queue.
 */
final readonly class RunExport
{
    public function __construct(
        public string $jobId,
    ) {}
}
