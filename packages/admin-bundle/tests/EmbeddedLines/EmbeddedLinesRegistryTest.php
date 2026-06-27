<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\EmbeddedLines;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nubit\AdminBundle\EmbeddedLines\EmbeddedLinesRegistry;
use Nubit\AdminBundle\Tests\EmbeddedLines\Fixture\EmbeddedLineFixture;
use Nubit\AdminBundle\Tests\EmbeddedLines\Fixture\ImplicitRouteEmbeddedLineFixture;
use PHPUnit\Framework\TestCase;

final class EmbeddedLinesRegistryTest extends TestCase
{
    public function testDiscoversEmbeddedLinesRouteFromAttribute(): void
    {
        $metadata = new ClassMetadata(EmbeddedLineFixture::class);
        $metadata->setPrimaryTable(['name' => 'sales_document_line']);

        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([$metadata]);

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getMetadataFactory')->willReturn($metadataFactory);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagers')->willReturn(['default' => $manager]);

        $definitions = (new EmbeddedLinesRegistry($managerRegistry))->all();

        self::assertCount(1, $definitions);
        $definition = $definitions[array_key_first($definitions)];
        self::assertSame(EmbeddedLineFixture::class, $definition->entityClass);
        self::assertSame('/api/sales_document_lines', $definition->routePath);
        self::assertSame('document', $definition->parentProperty);
        self::assertSame('document', $definition->parentQueryParam);
        self::assertSame(['document:read'], $definition->normalizationGroups);
    }

    public function testDeprecatedWhenRouteIsOmitted(): void
    {
        $metadata = new ClassMetadata(ImplicitRouteEmbeddedLineFixture::class);
        $metadata->setPrimaryTable(['name' => 'implicit_route_line']);

        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([$metadata]);

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getMetadataFactory')->willReturn($metadataFactory);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagers')->willReturn(['default' => $manager]);

        $this->expectDeprecation();
        $this->expectDeprecationMessage('Omitting the route on #[EmbeddedLines]');

        $definitions = (new EmbeddedLinesRegistry($managerRegistry))->all();

        self::assertSame('/api/implicit_route_lines', $definitions[array_key_first($definitions)]->routePath);
    }
}