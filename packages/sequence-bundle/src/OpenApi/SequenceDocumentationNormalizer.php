<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\OpenApi;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\SequenceBundle\Sequence\SequenceRegistry;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Injects class-level {@code x-sequence} metadata into the Hydra API doc so
 * SmartCrudPage can hide server-allocated fields without manual x-crud hints.
 */
final class SequenceDocumentationNormalizer implements NormalizerInterface
{
    /** @var array<string, string>|null */
    private ?array $shortNameToClass = null;

    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly SequenceRegistry $registry,
        private readonly ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
    ) {
    }

    /** @return array<mixed> */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        /** @var array<mixed> $doc */
        $doc = $this->inner->normalize($object, $format, $context);

        foreach (['hydra:', ''] as $prefix) {
            $classesKey = $prefix.'supportedClass';
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
                if (null === $fqcn) {
                    continue;
                }

                $sequence = $this->registry->getByEntityClass($fqcn);
                if (null !== $sequence) {
                    $class['x-sequence'] = $sequence->toOpenApi();
                }
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
            $this->shortNameToClass = [];
            foreach ($this->resourceNameCollectionFactory->create() as $resourceClass) {
                $metadataCollection = $this->resourceMetadataCollectionFactory->create($resourceClass);
                foreach ($metadataCollection as $metadata) {
                    $name = $metadata->getShortName();
                    if (\is_string($name) && $name !== '') {
                        $this->shortNameToClass[$name] = $resourceClass;
                        break;
                    }
                }
            }
        }

        return $this->shortNameToClass[$shortName] ?? null;
    }
}