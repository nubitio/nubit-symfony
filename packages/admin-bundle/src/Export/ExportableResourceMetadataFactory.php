<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use Nubit\ApiPlatform\Attribute\Exportable;
use Override;
use ReflectionClass;

/**
 * Confines the "xlsx" format to resources marked #[Exportable].
 *
 * Registering an encoder as an api_platform format enables it on every
 * resource at once, which hands any authenticated user a whole-table dump of
 * anything the API exposes. Rather than police that at request time, this
 * factory removes the format from the metadata of resources that did not ask
 * for it: API Platform then refuses it natively with 406 Not Acceptable, and
 * the OpenAPI document stops advertising a format those resources reject.
 */
final readonly class ExportableResourceMetadataFactory implements ResourceMetadataCollectionFactoryInterface
{
    /** @var array<string, list<string>> */
    private array $formatsWithoutExport;

    /** @param array<string, list<string>|string> $formats The api_platform.formats map. */
    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $decorated,
        array $formats,
        private string $exportFormat = XlsxEncoder::FORMAT,
    ) {
        $this->formatsWithoutExport = self::withoutFormat($formats, $exportFormat);
    }

    #[Override]
    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $metadata = $this->decorated->create($resourceClass);
        $exportable = self::exportableAttribute($resourceClass);

        foreach ($metadata as $index => $resource) {
            if (!$resource instanceof ApiResource) {
                continue;
            }

            $metadata[$index] = $this->withExportFormatConfinedTo($resource, $exportable);
        }

        return $metadata;
    }

    private function withExportFormatConfinedTo(ApiResource $resource, ?Exportable $exportable): ApiResource
    {
        $operations = $resource->getOperations();
        if ($operations === null) {
            return $resource;
        }

        /** @var array<string, HttpOperation> $rewritten */
        $rewritten = [];
        foreach ($operations as $name => $operation) {
            // Operations is typed to HttpOperation; anything else is not ours
            // to rewrite and is carried over untouched by the collection.
            if (!$operation instanceof HttpOperation) {
                continue;
            }

            $name = (string) $name;
            $rewritten[$name] = self::allowsExport($exportable, $name, $operation)
                ? $operation
                : $operation->withOutputFormats($this->resolveFormats($operation));
        }

        return $resource->withOperations(new Operations($rewritten));
    }

    /**
     * Unlisted, #[Exportable] covers the resource's safe reads only. A write
     * returning a spreadsheet of the row it just persisted is not what the
     * attribute is for, and advertising it in the OpenAPI document invites
     * clients to ask for it.
     */
    private static function allowsExport(?Exportable $exportable, string $name, HttpOperation $operation): bool
    {
        if ($exportable === null) {
            return false;
        }

        return $exportable->operations === null
            ? 'GET' === $operation->getMethod()
            : in_array($name, $exportable->operations, true);
    }

    /**
     * An operation that already narrowed its own formats keeps that choice,
     * minus the export format; one that inherited the global list gets the
     * global list minus the export format.
     *
     * @return array<string, list<string>>
     */
    private function resolveFormats(HttpOperation $operation): array
    {
        $declared = $operation->getOutputFormats();
        if (!is_array($declared)) {
            return $this->formatsWithoutExport;
        }

        return self::withoutFormat($declared, $this->exportFormat);
    }

    /**
     * @param array<array-key, mixed> $formats
     *
     * @return array<string, list<string>>
     */
    private static function withoutFormat(array $formats, string $excluded): array
    {
        $remaining = [];
        foreach ($formats as $format => $mimeTypes) {
            $format = (string) $format;
            if ($format === $excluded) {
                continue;
            }

            $remaining[$format] = array_values(array_map(strval(...), (array) $mimeTypes));
        }

        return $remaining;
    }

    private static function exportableAttribute(string $resourceClass): ?Exportable
    {
        if (!class_exists($resourceClass)) {
            return null;
        }

        $attributes = (new ReflectionClass($resourceClass))->getAttributes(Exportable::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
