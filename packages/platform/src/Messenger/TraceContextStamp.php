<?php

declare(strict_types=1);

namespace Nubit\Platform\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class TraceContextStamp implements StampInterface
{
    public function __construct(
        public ?string $traceparent,
        public ?string $tracestate = null,
    ) {}

    /** @return array{traceparent?: string, tracestate?: string} */
    public function toCarrier(): array
    {
        $carrier = [];
        if (null !== $this->traceparent && '' !== $this->traceparent) {
            $carrier['traceparent'] = $this->traceparent;
        }
        if (null !== $this->tracestate && '' !== $this->tracestate) {
            $carrier['tracestate'] = $this->tracestate;
        }

        return $carrier;
    }
}
