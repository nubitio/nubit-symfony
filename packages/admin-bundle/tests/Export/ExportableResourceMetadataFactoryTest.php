<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Export;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use Nubit\AdminBundle\Export\ExportableResourceMetadataFactory;
use Nubit\ApiPlatform\Attribute\Exportable;
use PHPUnit\Framework\TestCase;

#[Exportable]
final class ExportableThing {}

#[Exportable(operations: ['thing_get_collection'])]
final class SelectivelyExportableThing {}

final class PlainThing {}

/**
 * The xlsx encoder is registered as an api_platform format, which switches it
 * on for every resource at once. This factory is what takes it back off, so
 * these tests pin which operations keep it — a resource that silently retains
 * the format hands out whole-table dumps nobody asked for.
 */
final class ExportableResourceMetadataFactoryTest extends TestCase
{
    private const array FORMATS = [
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'json' => ['application/json'],
        'jsonld' => ['application/ld+json'],
    ];

    public function testAResourceWithoutTheAttributeLosesTheFormatEverywhere(): void
    {
        foreach ($this->create(PlainThing::class) as $name => $operation) {
            self::assertFalse(self::exportsXlsx($operation), $name);
        }
    }

    public function testStrippingTheFormatLeavesTheOthersInPlace(): void
    {
        $formats = $this->create(PlainThing::class)['thing_get_collection']->getOutputFormats();

        self::assertSame(['json' => ['application/json'], 'jsonld' => ['application/ld+json']], $formats);
    }

    public function testAnExportableResourceKeepsTheFormatOnItsReads(): void
    {
        $operations = $this->create(ExportableThing::class);

        self::assertTrue(self::exportsXlsx($operations['thing_get_collection']));
        self::assertTrue(self::exportsXlsx($operations['thing_get']));
    }

    /** An allowed operation is left untouched, so it inherits the global list. */
    public function testAnAllowedOperationIsNotRewritten(): void
    {
        self::assertNull($this->create(ExportableThing::class)['thing_get']->getOutputFormats());
    }

    /**
     * A write answering with a spreadsheet of the row it just saved is not what
     * the attribute is for, and advertising it invites clients to ask.
     */
    public function testAnExportableResourceStillLosesTheFormatOnWrites(): void
    {
        $operations = $this->create(ExportableThing::class);

        self::assertFalse(self::exportsXlsx($operations['thing_post']));
        self::assertFalse(self::exportsXlsx($operations['thing_delete']));
    }

    public function testAnExplicitOperationListWinsOverTheReadDefault(): void
    {
        $operations = $this->create(SelectivelyExportableThing::class);

        self::assertTrue(self::exportsXlsx($operations['thing_get_collection']));
        self::assertFalse(self::exportsXlsx($operations['thing_get']));
    }

    /** An operation that already narrowed its formats keeps that choice. */
    public function testAnOperationWithItsOwnFormatsOnlyLosesTheExportOne(): void
    {
        $metadata = new ResourceMetadataCollection(PlainThing::class, [
            new ApiResource(operations: [
                'thing_get_collection' => (new GetCollection())->withOutputFormats([
                    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                    'json' => ['application/json'],
                ]),
            ]),
        ]);

        $operations = $this->operationsOf($this->factory($metadata, PlainThing::class)->create(PlainThing::class));

        self::assertSame(['json' => ['application/json']], $operations['thing_get_collection']->getOutputFormats());
    }

    public function testAResourceWithoutOperationsPassesThroughUntouched(): void
    {
        $resource = new ApiResource();
        $metadata = new ResourceMetadataCollection(PlainThing::class, [$resource]);

        $result = $this->factory($metadata, PlainThing::class)->create(PlainThing::class);

        self::assertSame($resource, $result[0]);
    }

    public function testAnUnknownClassIsTreatedAsNotExportable(): void
    {
        $metadata = new ResourceMetadataCollection('App\\Entity\\Ghost', [
            new ApiResource(operations: [
                'thing_get_collection' => new GetCollection(),
            ]),
        ]);

        $operations = $this->operationsOf($this->factory($metadata, 'App\\Entity\\Ghost')->create(
            'App\\Entity\\Ghost',
        ));

        self::assertFalse(self::exportsXlsx($operations['thing_get_collection']));
    }

    public function testTheDecoratedFactoryIsAskedForTheSameResource(): void
    {
        $seen = null;
        $decorated = new class($seen) implements ResourceMetadataCollectionFactoryInterface {
            public function __construct(
                private mixed &$seen,
            ) {}

            public function create(string $resourceClass): ResourceMetadataCollection
            {
                $this->seen = $resourceClass;

                return new ResourceMetadataCollection($resourceClass, []);
            }
        };

        (new ExportableResourceMetadataFactory($decorated, self::FORMATS))->create(PlainThing::class);

        self::assertSame(PlainThing::class, $seen);
    }

    // ── harness ───────────────────────────────────────────────────────────

    /** @return array<string, HttpOperation> */
    private function create(string $resourceClass): array
    {
        $metadata = new ResourceMetadataCollection($resourceClass, [
            new ApiResource(operations: [
                'thing_get_collection' => new GetCollection(),
                'thing_get' => new Get(),
                'thing_post' => new Post(),
                'thing_delete' => new Delete(),
            ]),
        ]);

        return $this->operationsOf($this->factory($metadata, $resourceClass)->create($resourceClass));
    }

    /**
     * The effective answer to "can this operation be asked for a spreadsheet":
     * a null outputFormats means the operation inherits the configured list,
     * which still contains xlsx.
     */
    private static function exportsXlsx(HttpOperation $operation): bool
    {
        return isset(($operation->getOutputFormats() ?? self::FORMATS)['xlsx']);
    }

    /** @return array<string, HttpOperation> */
    private function operationsOf(ResourceMetadataCollection $metadata): array
    {
        $resource = $metadata[0];
        self::assertInstanceOf(ApiResource::class, $resource);

        $operations = [];
        foreach ($resource->getOperations() ?? [] as $name => $operation) {
            self::assertInstanceOf(HttpOperation::class, $operation);
            $operations[(string) $name] = $operation;
        }

        return $operations;
    }

    private function factory(
        ResourceMetadataCollection $metadata,
        string $resourceClass,
    ): ExportableResourceMetadataFactory {
        $decorated = new class($metadata, $resourceClass) implements ResourceMetadataCollectionFactoryInterface {
            public function __construct(
                private readonly ResourceMetadataCollection $metadata,
                private readonly string $resourceClass,
            ) {}

            public function create(string $resourceClass): ResourceMetadataCollection
            {
                return (
                    $resourceClass === $this->resourceClass
                        ? $this->metadata
                        : new ResourceMetadataCollection($resourceClass, [])
                );
            }
        };

        return new ExportableResourceMetadataFactory($decorated, self::FORMATS);
    }
}
