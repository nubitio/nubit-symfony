<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\AdminBundle\Import\Exception\ImportException;
use Nubit\ApiPlatform\Attribute\Importable;

/**
 * Reads `#[Importable]`, and resolves the URL segment that names a resource.
 *
 * As with documents, the segment is matched against resources API Platform
 * already publishes rather than treated as a class name. An importer that
 * accepted a class name from the URL would let a caller pick which class an
 * uploaded file gets written into.
 */
final class ImportableRegistry
{
    /** @var array<class-string, Importable|null> */
    private array $cache = [];

    /** @var array<string, class-string>|null */
    private ?array $index = null;

    public function __construct(
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
    ) {}

    public function find(string $resourceClass): ?Importable
    {
        if (array_key_exists($resourceClass, $this->cache)) {
            return $this->cache[$resourceClass];
        }

        if (!class_exists($resourceClass)) {
            return $this->cache[$resourceClass] = null;
        }

        $attributes = (new \ReflectionClass($resourceClass))->getAttributes(Importable::class);

        return $this->cache[$resourceClass] = [] === $attributes ? null : $attributes[0]->newInstance();
    }

    public function get(string $resourceClass): Importable
    {
        return (
            $this->find($resourceClass) ?? throw new ImportException(sprintf(
                'Resource "%s" is not importable: add #[Importable] to it.',
                $resourceClass,
            ))
        );
    }

    /** @return class-string */
    public function resolveClass(string $segment): string
    {
        $index = $this->index ??= $this->buildIndex();
        $key = strtolower($segment);

        return $index[$key] ?? throw new ImportException(sprintf(
            'No importable resource is published as "%s".',
            $segment,
        ));
    }

    /** @return array<string, class-string> */
    private function buildIndex(): array
    {
        $index = [];

        /** @var class-string $class */
        foreach ($this->resourceNames->create() as $class) {
            if (null === $this->find($class)) {
                continue;
            }

            $short = (new \ReflectionClass($class))->getShortName();
            $dashed = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short));

            foreach ([strtolower($short), strtolower($short) . 's', $dashed, $dashed . 's'] as $alias) {
                $index[$alias] = $class;
            }
        }

        return $index;
    }
}
