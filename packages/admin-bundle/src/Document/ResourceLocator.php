<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document;

use Doctrine\ORM\EntityManagerInterface;
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
 */
final readonly class ResourceLocator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ResourceSegmentIndex $segments,
        private PrintableRegistry $printables,
    ) {}

    public function locate(string $resource, string $id): object
    {
        $class = $this->resolveClass($resource);

        $subject = $this->entityManager->find($class, $id);
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
