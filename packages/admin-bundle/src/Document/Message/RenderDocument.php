<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document\Message;

/**
 * Carries only the document's identifier.
 *
 * The row is written before the message is dispatched, so everything the worker
 * needs is already durable. Putting the resource — or the rendered HTML — in
 * the envelope would make the message a second source of truth that can drift
 * from the row while it waits in the queue.
 */
final readonly class RenderDocument
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public string $documentId,
        public array $options = [],
    ) {}
}
