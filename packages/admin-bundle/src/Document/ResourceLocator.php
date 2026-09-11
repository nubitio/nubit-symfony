<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document;

use Nubit\AdminBundle\Authorization\ScopedEntityLocator;
use Nubit\AdminBundle\Resource\ResourceSegmentIndex;
use Nubit\Platform\Exception\NotFoundException;

/**
 * Resolves the `{resource}` segment of a document URL to an entity.
 *
 * The segment is a short name — `invoices`, `purchase-orders` — never a class
 * name. Accepting a class name from the URL would let a caller name any class
 * in the application and have it loaded, so the lookup is restricted to
 * resources API Platform already publishes, and the mapping is built once from
 * that list rather than parsed out of the request.
 *
 * The lookup itself goes through {@see ScopedEntityLocator}, the same
 * row-scope-aware finder every other custom route uses, so a document or its
 * history is never reachable for a row the caller's own API operations would
 * have hidden — issuing and history are both document-shaped reads on
 * whatever the resource is, so they owe it the same scope that resource's
 * `GET` would enforce.
 */
final readonly class ResourceLocator
{
    public function __construct(
        private ScopedEntityLocator $locator,
        private ResourceSegmentIndex $segments,
        private PrintableRegistry $printables,
    ) {}

    public function locate(string $resource, string $id): object
    {
        $class = $this->resolveClass($resource);

        $subject = $this->locator->find($class, $id);
        if (null === $subject) {
            throw NotFoundException::forResource($resource, $id);
        }

        return $subject;
    }

    /** @return class-string */
    public function resolveClass(string $resource): string
    {
        $class = $this->segments->resolve($resource);

        // Published is not enough: a document route must only reach resources
        // that declare themselves printable.
        if (!$this->printables->isPrintable($class)) {
            throw new NotFoundException(sprintf('No printable resource is published as "%s".', $resource));
        }

        return $class;
    }
}
