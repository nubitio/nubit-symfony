<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\OpenApi;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRegistry;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Injects class-level {@code x-embedded-lines} metadata on parent resources so
 * SchemaCrudPage can infer formDetail line fields from the API doc automatically.
 */
final class EmbeddedLinesDocumentationNormalizer implements NormalizerInterface
{
    /** @var array<string, string>|null */
    private ?array $shortNameToClass = null;

    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly EmbeddedLinesRegistry $registry,
        private readonly ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
    ) {
    }

    /** @return array<mixed> */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        /** @var array<mixed> $doc */
        $doc = $this->inner->normalize($object, $format, $context);

        $bindingsByParent = [];
        foreach ($this->registry->all() as $definition) {
            if ($definition->parentEntityClass === '') {
                continue;
            }
            $bindingsByParent[$definition->parentEntityClass][] = $definition->toOpenApi();
        }

        if ($bindingsByParent === []) {
            return $doc;
        }

        foreach (['hydra:', ''] as $prefix) {
            $classesKey = $prefix . 'supportedClass';
            if (!isset($doc[$classesKey]) || !\is_array($doc[$classesKey])) {
                continue;
            }

            foreach ($doc[$classesKey] as &$class) {
                if (!\is_array($class)) {
                    continue;
                }

                $classId = $class['@id'] ?? null;
                if (!\is_string($classId)) {
                    continue;
                }

                $fqcn = $this->resolveShortNameToClass(\ltrim($classId, '#'));
                if (null === $fqcn || !isset($bindingsByParent[$fqcn])) {
                    continue;
                }

                $class['x-embedded-lines'] = $bindingsByParent[$fqcn];
            }
            unset($class);
        }

        return $doc;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    private function resolveShortNameToClass(string $shortName): ?string
    {
        if ($this->shortNameToClass === null) {
            $map = [];
            foreach ($this->resourceNameCollectionFactory->create() as $class) {
                $map[substr($class, strrpos($class, '\\') + 1)] = $class;
            }
            $this->shortNameToClass = $map;
        }

        return $this->shortNameToClass[$shortName] ?? null;
    }
}