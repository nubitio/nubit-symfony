<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Tenant\Model;

use InvalidArgumentException;
use Nubit\Platform\Tenant\Model\TenantDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantDescriptor::class)]
final class TenantDescriptorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor – happy path
    // -------------------------------------------------------------------------

    public function testHappyPathSetsAllFields(): void
    {
        $descriptor = new TenantDescriptor(
            id: 1,
            name: 'acme',
            connectionName: 'tenant_acme',
            primaryDomain: 'acme.example.test',
            plan: 'pro',
            status: 'active',
            attributes: ['extra' => 'value'],
        );

        self::assertSame(1, $descriptor->id);
        self::assertSame('acme', $descriptor->name);
        self::assertSame('tenant_acme', $descriptor->connectionName);
        self::assertSame('acme.example.test', $descriptor->primaryDomain);
        self::assertSame('pro', $descriptor->plan);
        self::assertSame('active', $descriptor->status);
        self::assertSame(['extra' => 'value'], $descriptor->attributes);
    }

    public function testOptionalFieldsDefaultToNull(): void
    {
        $descriptor = new TenantDescriptor(id: 1, name: 'acme');

        self::assertNull($descriptor->connectionName);
        self::assertNull($descriptor->primaryDomain);
        self::assertNull($descriptor->plan);
        self::assertNull($descriptor->status);
        self::assertSame([], $descriptor->attributes);
    }

    // -------------------------------------------------------------------------
    // Constructor – validation
    // -------------------------------------------------------------------------

    public function testIdZeroThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantDescriptor(id: 0, name: 'acme');
    }

    public function testNegativeIdThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantDescriptor(id: -1, name: 'acme');
    }

    public function testEmptyNameThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantDescriptor(id: 1, name: '');
    }

    public function testWhitespaceOnlyNameThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantDescriptor(id: 1, name: '   ');
    }

    // -------------------------------------------------------------------------
    // fromArray – canonical keys
    // -------------------------------------------------------------------------

    public function testFromArrayWithFullArraySetsAllFields(): void
    {
        $descriptor = TenantDescriptor::fromArray([
            'id' => 5,
            'name' => 'beta',
            'connection' => 'tenant_beta',
            'primary_domain' => 'beta.example.test',
            'plan' => 'starter',
            'status' => 'active',
        ]);

        self::assertSame(5, $descriptor->id);
        self::assertSame('beta', $descriptor->name);
        self::assertSame('tenant_beta', $descriptor->connectionName);
        self::assertSame('beta.example.test', $descriptor->primaryDomain);
        self::assertSame('starter', $descriptor->plan);
        self::assertSame('active', $descriptor->status);
    }

    // -------------------------------------------------------------------------
    // fromArray – legacy key aliases
    // -------------------------------------------------------------------------

    public function testFromArrayWithLegacyDomainKeyMapsToPrimaryDomain(): void
    {
        $descriptor = TenantDescriptor::fromArray([
            'id' => 1,
            'name' => 'acme',
            'domain' => 'legacy.example.test',
        ]);

        self::assertSame('legacy.example.test', $descriptor->primaryDomain);
    }

    public function testFromArrayWithLegacyConnectionNameKeyMapsToConnectionName(): void
    {
        $descriptor = TenantDescriptor::fromArray([
            'id' => 1,
            'name' => 'acme',
            'connection_name' => 'tenant_legacy',
        ]);

        self::assertSame('tenant_legacy', $descriptor->connectionName);
    }

    // -------------------------------------------------------------------------
    // fromArray – empty string → null coercion
    // -------------------------------------------------------------------------

    public function testFromArrayWithEmptyStringValuesProducesNullForNullableFields(): void
    {
        $descriptor = TenantDescriptor::fromArray([
            'id' => 1,
            'name' => 'acme',
            'connection' => '',
            'primary_domain' => '',
            'plan' => '',
            'status' => '',
        ]);

        self::assertNull($descriptor->connectionName);
        self::assertNull($descriptor->primaryDomain);
        self::assertNull($descriptor->plan);
        self::assertNull($descriptor->status);
    }

    // -------------------------------------------------------------------------
    // toArray
    // -------------------------------------------------------------------------

    public function testToArrayMergesAttributesWithCanonicalFieldsAndCanonicalFieldsOverride(): void
    {
        $descriptor = TenantDescriptor::fromArray([
            'id' => 3,
            'name' => 'gamma',
            'connection' => 'tenant_gamma',
            'primary_domain' => 'gamma.example.test',
            'plan' => 'pro',
            'status' => 'active',
            'extra_key' => 'extra_value',
            // Simulate a stale value in the raw array that canonical fields must override.
            'id_stale' => 999,
        ]);

        $array = $descriptor->toArray();

        self::assertSame(3, $array['id']);
        self::assertSame('gamma', $array['name']);
        self::assertSame('tenant_gamma', $array['connection']);
        self::assertSame('gamma.example.test', $array['primary_domain']);
        self::assertSame('pro', $array['plan']);
        self::assertSame('active', $array['status']);
        // Extra attribute from the raw input is preserved.
        self::assertSame('extra_value', $array['extra_key']);
    }

    public function testToArrayCanonicalFieldsOverrideStaleAttributeValues(): void
    {
        // Build a descriptor where the raw attributes contain a stale 'name'
        // that differs from the canonical property – canonical must win.
        $descriptor = new TenantDescriptor(
            id: 7,
            name: 'canonical-name',
            attributes: ['id' => 999, 'name' => 'stale-name'],
        );

        $array = $descriptor->toArray();

        self::assertSame(7, $array['id']);
        self::assertSame('canonical-name', $array['name']);
    }
}
