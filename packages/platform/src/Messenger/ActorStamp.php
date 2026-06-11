<?php

declare(strict_types=1);

namespace Nubit\Platform\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class ActorStamp implements StampInterface
{
    public function __construct(
        public ?string $actorIdentifier,
        public ?string $channel,
        public ?string $commandName = null,
    ) {
    }
}
