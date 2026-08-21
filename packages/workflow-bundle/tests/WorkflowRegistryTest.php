<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Tests;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ObjectManager;
use Nubit\WorkflowBundle\Attribute\Workflow;
use Nubit\WorkflowBundle\Workflow\WorkflowMetadata;
use Nubit\WorkflowBundle\Workflow\WorkflowRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRegistryTest extends TestCase
{
    #[Test]
    public function it_infers_the_route_prefix_from_the_get_collection_uri_template(): void
    {
        $registry = $this->createRegistry(WorkflowedCategory::class, new ApiResource(operations: [new GetCollection(
            uriTemplate: '/categories',
        )]));

        $definition = $registry->getByEntityClass(WorkflowedCategory::class);

        self::assertNotNull($definition);
        self::assertSame('/api/categories', $definition->routePrefix);
        self::assertSame('api_categories', $definition->routeKey);
    }

    #[Test]
    public function it_prefixes_a_uri_template_declared_without_a_leading_slash(): void
    {
        $registry = $this->createRegistry(WorkflowedCategory::class, new ApiResource(operations: [new GetCollection(
            uriTemplate: 'categories',
        )]));

        $definition = $registry->getByEntityClass(WorkflowedCategory::class);

        self::assertNotNull($definition);
        self::assertSame('/api/categories', $definition->routePrefix);
    }

    #[Test]
    public function it_falls_back_to_the_snake_case_heuristic_without_a_collection_operation(): void
    {
        $registry = $this->createRegistry(WorkflowedSalesDocument::class, new ApiResource(operations: []));

        $definition = $registry->getByEntityClass(WorkflowedSalesDocument::class);

        self::assertNotNull($definition);
        self::assertSame('/api/workflowed_sales_documents', $definition->routePrefix);
    }

    #[Test]
    public function it_honours_an_explicit_route_prefix_over_the_api_platform_metadata(): void
    {
        $registry = $this->createRegistry(WorkflowedWithExplicitPrefix::class, new ApiResource(operations: [new GetCollection(
            uriTemplate: '/ignored',
        )]));

        $definition = $registry->getByEntityClass(WorkflowedWithExplicitPrefix::class);

        self::assertNotNull($definition);
        self::assertSame('/api/orders', $definition->routePrefix);
    }

    #[Test]
    public function it_skips_entities_without_a_workflow_attribute(): void
    {
        $registry = $this->createRegistry(PlainEntity::class, new ApiResource(operations: [new GetCollection(
            uriTemplate: '/plain',
        )]));

        self::assertSame([], $registry->all());
        self::assertNull($registry->getByEntityClass(PlainEntity::class));
    }

    private function createRegistry(string $entityClass, ApiResource $resource): WorkflowRegistry
    {
        $classMetadata = $this->createStub(ClassMetadata::class);
        $classMetadata->method('getName')->willReturn($entityClass);

        $metadataFactory = $this->createStub(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([$classMetadata]);

        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getMetadataFactory')->willReturn($metadataFactory);

        $managerRegistry = $this->createStub(ManagerRegistry::class);
        $managerRegistry->method('getManagers')->willReturn([$manager]);

        $resourceFactory = $this->createStub(ResourceMetadataCollectionFactoryInterface::class);
        $resourceFactory->method('create')->willReturn(new ResourceMetadataCollection($entityClass, [$resource]));

        return new WorkflowRegistry($managerRegistry, $resourceFactory, new WorkflowMetadata());
    }
}

#[Workflow(field: 'status', transitions: ['publish' => ['from' => ['draft'], 'to' => 'published']])]
final class WorkflowedCategory
{
    public string $status = 'draft';
}

#[Workflow(field: 'status', transitions: ['issue' => ['from' => ['draft'], 'to' => 'issued']])]
final class WorkflowedSalesDocument
{
    public string $status = 'draft';
}

#[Workflow(field: 'status', transitions: ['pay' => ['from' => ['open'], 'to' => 'paid']], routePrefix: '/api/orders')]
final class WorkflowedWithExplicitPrefix
{
    public string $status = 'open';
}

final class PlainEntity
{
    public string $status = 'open';
}
