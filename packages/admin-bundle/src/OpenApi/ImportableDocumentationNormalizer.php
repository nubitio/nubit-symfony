<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\OpenApi;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\AdminBundle\Import\ImportableRegistry;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Publishes `x-importable` for resources that declare `#[Importable]`.
 *
 * The import screen is generated from this: which fields can be filled, which
 * are required, which form the natural key. A frontend that had to be told
 * separately would drift from the backend the first time a field was added, and
 * an agent asked to "add an import for suppliers" would have two places to
 * change instead of one.
 */
final class ImportableDocumentationNormalizer implements NormalizerInterface
{
    /** @var array<string, class-string>|null */
    private ?array $shortNameToClass = null;

    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly ImportableRegistry $registry,
        private readonly ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory,
    ) {}

    /** @return array<mixed> */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        /** @var array<mixed> $doc */
        $doc = $this->inner->normalize($data, $format, $context);

        foreach (['hydra:', ''] as $prefix) {
            $classesKey = $prefix . 'supportedClass';
            if (!isset($doc[$classesKey]) || !is_array($doc[$classesKey])) {
                continue;
            }

            foreach ($doc[$classesKey] as &$class) {
                if (!is_array($class)) {
                    continue;
                }

                $classId = $class['@id'] ?? null;
                if (!is_string($classId)) {
                    continue;
                }

                $fqcn = $this->resolveShortNameToClass(ltrim($classId, '#'));
                $importable = null === $fqcn ? null : $this->registry->find($fqcn);
                if (null === $importable || null === $fqcn) {
                    continue;
                }

                $class['x-importable'] = [
                    'fields' => $importable->fields,
                    'required' => $importable->required,
                    'naturalKey' => $importable->naturalKey,
                    'maxRows' => $importable->maxRows,
                    'uploadUrl' => sprintf('/api/imports/%s', $this->urlSegment($fqcn)),
                    // Stated rather than implied: a client must not offer an
                    // "import now" button that skips the review step.
                    'requiresReview' => true,
                ];
            }
            unset($class);
        }

        return $doc;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    /** @return array<string, bool|null> */
    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    private function urlSegment(string $fqcn): string
    {
        $short = substr($fqcn, strrpos($fqcn, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short)) . 's';
    }

    private function resolveShortNameToClass(string $shortName): ?string
    {
        if (null === $this->shortNameToClass) {
            /** @var array<string, class-string> $map */
            $map = [];
            /** @var class-string $class */
            foreach ($this->resourceNameCollectionFactory->create() as $class) {
                $map[substr($class, strrpos($class, '\\') + 1)] = $class;
            }
            $this->shortNameToClass = $map;
        }

        return $this->shortNameToClass[$shortName] ?? null;
    }
}
