<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document;

use Nubit\ApiPlatform\Attribute\Printable;
use Nubit\Platform\Exception\NotFoundException;

/**
 * Reads `#[Printable]` off resource classes.
 *
 * Reflection is cached per class: a grid listing a hundred rows asks the same
 * question a hundred times, and reflection is the one part of issuing a
 * document that has no business being repeated.
 */
final class PrintableRegistry
{
    /** @var array<class-string, Printable|null> */
    private array $cache = [];

    public function find(string|object $resource): ?Printable
    {
        $class = $this->normalizeClass($resource);

        if (array_key_exists($class, $this->cache)) {
            return $this->cache[$class];
        }

        $attributes = (new \ReflectionClass($class))->getAttributes(Printable::class);

        return $this->cache[$class] = [] === $attributes ? null : $attributes[0]->newInstance();
    }

    public function get(string|object $resource): Printable
    {
        $printable = $this->find($resource);

        if (null === $printable) {
            throw new NotFoundException(sprintf(
                'Resource "%s" is not printable: add #[Printable] to it.',
                $this->normalizeClass($resource),
            ));
        }

        return $printable;
    }

    public function isPrintable(string|object $resource): bool
    {
        return null !== $this->find($resource);
    }

    /** @return class-string */
    private function normalizeClass(string|object $resource): string
    {
        $class = is_object($resource) ? $resource::class : $resource;

        if (!class_exists($class)) {
            throw new NotFoundException(sprintf('Unknown resource class "%s".', $class));
        }

        // A Doctrine proxy reports its own generated class name, which carries
        // none of the application's attributes.
        $parent = get_parent_class($class);
        if (false !== $parent && str_contains($class, '\\__CG__\\')) {
            return $parent;
        }

        return $class;
    }
}
