<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Doctrine\ORM\EntityManagerInterface;
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
final class ResourceLocator
{
    /** @var array<string, class-string>|null */
    private ?array $index = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
        private readonly PrintableRegistry $printables,
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
        $index = $this->index ??= $this->buildIndex();
        $key = strtolower($resource);

        if (!isset($index[$key])) {
            throw new NotFoundException(sprintf('No printable resource is published as "%s".', $resource));
        }

        return $index[$key];
    }

    /** @return array<string, class-string> */
    private function buildIndex(): array
    {
        $index = [];

        /** @var class-string $class */
        foreach ($this->resourceNames->create() as $class) {
            if (!$this->printables->isPrintable($class)) {
                continue;
            }

            $short = (new \ReflectionClass($class))->getShortName();

            // Both the plural URL segment API Platform generates and the bare
            // short name resolve, so a caller can use whichever it knows.
            $index[strtolower($short)] = $class;
            $index[strtolower($short) . 's'] = $class;
            $index[self::dasherize($short)] = $class;
            $index[self::dasherize($short) . 's'] = $class;
        }

        return $index;
    }

    private static function dasherize(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}
