<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\OpenApi;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\ApiPlatform\Doctrine\GridScaleRegistry;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Publishes `x-grid-scale`: how a resource expects to be paginated.
 *
 * Read from what is actually in force, not only from the attribute. API
 * Platform's `paginationViaCursor` and `paginationPartial` are what the server
 * really does; `#[GridScale]` describes the intent. Publishing the attribute
 * alone would let the two disagree, and the client would be the one to find out.
 */
final class GridScaleDocumentationNormalizer implements NormalizerInterface
{
    /** @var array<string, class-string>|null */
    private ?array $shortNameToClass = null;

    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly GridScaleRegistry $registry,
        private readonly ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadata,
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
                if (null === $fqcn) {
                    continue;
                }

                $scale = $this->registry->find($fqcn);
                $enforced = $this->enforcedPagination($fqcn);

                if (null === $scale && [] === $enforced) {
                    continue;
                }

                $cursorField = $enforced['cursorField'] ?? $scale?->cursorField;

                $class['x-grid-scale'] = [
                    // "cursor" is the promise the client has to keep: page
                    // through by following links, and do not re-sort.
                    'mode' => null !== $cursorField ? 'cursor' : 'page',
                    'cursorField' => $cursorField,
                    'cursorDirection' => $enforced['cursorDirection'] ?? $scale?->cursorDirection ?? 'DESC',
                    // False means the footer's total is an estimate or absent,
                    // so a client must not render it as a precise number.
                    'exactCount' => $enforced['partial'] ?? false ? false : $scale?->exactCount ?? true,
                    'inlineExportLimit' => $scale?->inlineExportLimit ?? 5000,
                    // Sorting on anything else returns pages that repeat and
                    // skip rows, so the server refuses it.
                    'sortableFields' => null !== $cursorField ? [$cursorField] : null,
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

    /**
     * What API Platform is configured to actually do for this resource.
     *
     * @param class-string $fqcn
     *
     * @return array{cursorField?: string, cursorDirection?: string, partial?: bool}
     */
    private function enforcedPagination(string $fqcn): array
    {
        $enforced = [];

        try {
            $collection = $this->resourceMetadata->create($fqcn);
        } catch (\Throwable) {
            return $enforced;
        }

        foreach ($collection as $resource) {
            foreach ($resource->getOperations() ?? [] as $operation) {
                // CollectionOperationInterface is only a marker; the pagination
                // accessors live on HttpOperation.
                if (!$operation instanceof CollectionOperationInterface || !$operation instanceof HttpOperation) {
                    continue;
                }

                if (true === $operation->getPaginationPartial()) {
                    $enforced['partial'] = true;
                }

                $cursor = $operation->getPaginationViaCursor();
                if (is_array($cursor) && [] !== $cursor) {
                    $first = $cursor[0];
                    if (is_array($first) && isset($first['field']) && is_string($first['field'])) {
                        $enforced['cursorField'] = $first['field'];
                        $direction = $first['direction'] ?? 'DESC';
                        $enforced['cursorDirection'] = is_string($direction) ? $direction : 'DESC';
                    }
                }
            }
        }

        return $enforced;
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
