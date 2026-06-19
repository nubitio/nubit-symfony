<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nubit\Platform\Exception\ServiceException;
use Nubit\TenantBundle\Provider\RegistryTenantDatabaseUrlProvider;
use PHPUnit\Framework\TestCase;

final class RegistryTenantDatabaseUrlProviderTest extends TestCase
{
    public function testResolvesDatabaseUrlBySlug(): void
    {
        $tenant = new TenantWithDatabaseUrl('postgresql://tenant-db');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->with(['slug' => 'acme'])->willReturn($tenant);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $provider = new RegistryTenantDatabaseUrlProvider($entityManager, TenantWithDatabaseUrl::class);

        self::assertSame('postgresql://tenant-db', $provider->resolveDatabaseUrl('acme'));
    }

    public function testReturnsNullWhenTenantMissing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $provider = new RegistryTenantDatabaseUrlProvider($entityManager, TenantWithDatabaseUrl::class);

        self::assertNull($provider->resolveDatabaseUrl('missing'));
    }

    public function testRequiresGetDatabaseUrlOnCustomTenantEntity(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(new TenantWithoutDatabaseUrl());

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $provider = new RegistryTenantDatabaseUrlProvider($entityManager, TenantWithoutDatabaseUrl::class);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('must expose getDatabaseUrl()');

        $provider->resolveDatabaseUrl('acme');
    }
}

final class TenantWithDatabaseUrl
{
    public function __construct(private string $databaseUrl)
    {
    }

    public function getDatabaseUrl(): string
    {
        return $this->databaseUrl;
    }
}

final class TenantWithoutDatabaseUrl
{
}