<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Doctrine;

use Nubit\TenantBundle\Attribute\TenantScoped;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Doctrine\TenantScopedMetadata;
use PHPUnit\Framework\TestCase;

final class TenantScopedMetadataTest extends TestCase
{
    public function testDetectsTenantRootEntity(): void
    {
        $metadata = new TenantScopedMetadata(TenantRootFixture::class);

        self::assertTrue($metadata->isTenantRoot(TenantRootFixture::class));
        self::assertNull($metadata->resolveField(TenantRootFixture::class));
    }

    public function testReadsCustomFieldFromAttribute(): void
    {
        $metadata = new TenantScopedMetadata(null);

        self::assertSame('restaurant_id', $metadata->resolveField(CustomFieldFixture::class));
    }

    public function testDefaultsToTenantIdForOwnedInterface(): void
    {
        $metadata = new TenantScopedMetadata(null);

        self::assertSame('tenant_id', $metadata->resolveField(OwnedFixture::class));
        self::assertTrue($metadata->shouldStamp(OwnedFixture::class));
    }
}

#[TenantScoped(field: 'restaurant_id', stampOnPersist: false)]
final class CustomFieldFixture
{
}

final class OwnedFixture implements TenantOwnedInterface
{
    private ?int $tenantId = null;

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(?int $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }
}

final class TenantRootFixture
{
}