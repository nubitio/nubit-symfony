<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Resource;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\Platform\Exception\NotFoundException;

/**
 * Resolves the `{resource}` segment of a URL to a resource class.
 *
 * The segment is a short name — `invoices`, `stock-movements` — never a class
 * name, and it is matched against what API Platform already publishes. A route
 * that accepted a class name would let a caller name any class in the
 * application and have it loaded, printed or exported.
 *
 * Shared by the document and export routes, which need the same mapping for
 * different reasons; a second copy would be a second chance to get the
 * restriction wrong.
 */
final class ResourceSegmentIndex
{
    /** @var array<string, class-string>|null */
    private ?array $index = null;

    public function __construct(
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
    ) {}

    /** @return class-string */
    public function resolve(string $segment): string
    {
        $index = $this->index ??= $this->build();

        return (
            $index[strtolower(trim($segment))] ?? throw new NotFoundException(sprintf(
                'No published resource is addressed as "%s".',
                $segment,
            ))
        );
    }

    public function knows(string $segment): bool
    {
        $index = $this->index ??= $this->build();

        return isset($index[strtolower(trim($segment))]);
    }

    /** @return array<string, class-string> */
    private function build(): array
    {
        $index = [];

        /** @var class-string $class */
        foreach ($this->resourceNames->create() as $class) {
            $short = (new \ReflectionClass($class))->getShortName();
            $dashed = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short));
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));

            // Every spelling API Platform itself might generate, so a caller can
            // use whichever the URL bar showed them.
            foreach ([strtolower($short), $dashed, $snake] as $alias) {
                $index[$alias] = $class;
                $index[$alias . 's'] = $class;
            }
        }

        return $index;
    }
}
