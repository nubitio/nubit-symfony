<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nubit\Platform\Exception\ServiceException;
use Nubit\TenantBundle\Isolation\TenantIsolationTarget;
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

        static::assertSame('postgresql://tenant-db', $provider->resolveDatabaseUrl('acme'));
    }

    public function testReturnsNullWhenTenantMissing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $provider = new RegistryTenantDatabaseUrlProvider($entityManager, TenantWithDatabaseUrl::class);

        static::assertNull($provider->resolveDatabaseUrl('missing'));
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

    public function testResolvesDatabaseTargetFromControlPlaneTenant(): void
    {
        $tenant = new TenantWithDatabaseUrl('postgresql://tenant-db', TenantIsolationTarget::DATABASE);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->with(42)->willReturn($tenant);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $provider = new RegistryTenantDatabaseUrlProvider($entityManager, TenantWithDatabaseUrl::class);

        $target = $provider->resolveTarget('acme', 42);

        static::assertSame(TenantIsolationTarget::DATABASE, $target?->mode);
        static::assertSame('postgresql://tenant-db', $target?->databaseUrl);
    }

    public function testExplicitlyRejectsSchemaIsolationForTheDefaultProvider(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(new TenantWithDatabaseUrl('postgresql://tenant-db', 'schema'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $provider = new RegistryTenantDatabaseUrlProvider($entityManager, TenantWithDatabaseUrl::class);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('requires an application-owned TenantIsolationTargetProviderInterface');

        $provider->resolveTarget('acme');
    }

    public function testRetainsColumnAndDatabaseTargetCompatibility(): void
    {
        static::assertSame(TenantIsolationTarget::COLUMN, (new TenantIsolationTarget(TenantIsolationTarget::COLUMN))->mode);
        static::assertSame(TenantIsolationTarget::DATABASE, (new TenantIsolationTarget(TenantIsolationTarget::DATABASE, 'postgresql://tenant'))->mode);
    }

    public function testFailsClosedWhenDatabaseTargetHasNoUrl(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(new TenantWithDatabaseUrl('', TenantIsolationTarget::DATABASE));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $provider = new RegistryTenantDatabaseUrlProvider($entityManager, TenantWithDatabaseUrl::class);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('requires a non-empty database URL');

        $provider->resolveTarget('acme');
    }
}

final class TenantWithDatabaseUrl
{
    public function __construct(
        private string $databaseUrl,
        private string $isolationMode = TenantIsolationTarget::DATABASE,
    )
    {
    }

    public function getDatabaseUrl(): string
    {
        return $this->databaseUrl;
    }

    public function getIsolationMode(): string
    {
        return $this->isolationMode;
    }
}

final class TenantWithoutDatabaseUrl
{
}
