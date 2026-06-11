<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Cache;

use Nubit\Platform\Cache\CacheManager;
use Nubit\Platform\Tenant\Context\TenantContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for CacheManager.
 *
 * Focus: AC-CACHE-02 — auto-namespace by tenant prefix.
 * The underlying CacheItemPoolInterface is fully mocked so tests are fast
 * and isolated from infrastructure.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class CacheManagerTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $pool;

    protected function setUp(): void
    {
        $this->pool = $this->createMock(CacheItemPoolInterface::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCacheManager(?string $tenantName): CacheManager
    {
        $tenantContext = new TenantContext();
        if ($tenantName !== null) {
            $tenantContext->setTenant(1, $tenantName, "{$tenantName}.test", 'req-test');
        }

        return new CacheManager($this->pool, $tenantContext);
    }

    private function stubPoolItem(string $expectedKey, bool $isHit = false, mixed $value = null): CacheItemInterface&MockObject
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn($isHit);
        $item->method('get')->willReturn($value);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();

        $this->pool->method('getItem')
            ->with($expectedKey)
            ->willReturn($item);

        return $item;
    }

    // -------------------------------------------------------------------------
    // AC-CACHE-02: tenant context active → keys are prefixed with t.{name}.
    // -------------------------------------------------------------------------

    /** @test get() passes scoped key to pool when tenant is active */
    public function testGetUsesScopedKeyWhenTenantActive(): void
    {
        $manager = $this->makeCacheManager('acme');
        $this->stubPoolItem('t.acme.my_key', true, ['foo' => 'bar']);

        $result = $manager->get('my_key');

        self::assertSame(['foo' => 'bar'], $result);
    }

    /** @test get() passes raw key to pool when no tenant context */
    public function testGetPassesRawKeyWhenNoTenant(): void
    {
        $manager = $this->makeCacheManager(null);
        $this->stubPoolItem('my_key', true, 'value');

        $result = $manager->get('my_key');

        self::assertSame('value', $result);
    }

    /** @test set() persists under scoped key when tenant is active */
    public function testSetUsesScopedKeyWhenTenantActive(): void
    {
        $manager = $this->makeCacheManager('tenant_x');

        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with('hello')->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with(60)->willReturnSelf();

        $this->pool->expects(self::once())
            ->method('getItem')
            ->with('t.tenant_x.greet')
            ->willReturn($item);

        $this->pool->expects(self::once())->method('save')->with($item);

        $manager->set('greet', 'hello', 60);
    }

    /** @test has() checks scoped key */
    public function testHasUsesScopedKey(): void
    {
        $manager = $this->makeCacheManager('abc');

        $this->pool->expects(self::once())
            ->method('hasItem')
            ->with('t.abc.some_key')
            ->willReturn(true);

        self::assertTrue($manager->has('some_key'));
    }

    /** @test delete() (via forget()) deletes scoped key */
    public function testForgetDeletesScopedKey(): void
    {
        $manager = $this->makeCacheManager('myco');

        $this->pool->expects(self::once())
            ->method('deleteItem')
            ->with('t.myco.cache_key');

        $manager->forget('cache_key');
    }

    /** @test remember() returns cached value without calling callback when hit */
    public function testRememberReturnsCachedValueOnHit(): void
    {
        $manager = $this->makeCacheManager('demo');
        $this->stubPoolItem('t.demo.computed', true, 42);

        $callbackInvoked = false;
        $result = $manager->remember('computed', function () use (&$callbackInvoked): int {
            $callbackInvoked = true;
            return 99;
        }, 120);

        self::assertSame(42, $result);
        self::assertFalse($callbackInvoked);
    }

    /** @test remember() calls callback and stores result when miss */
    public function testRememberCallsCallbackOnMiss(): void
    {
        $manager = $this->makeCacheManager('demo');

        $item = $this->createStub(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();

        $this->pool->method('getItem')
            ->with('t.demo.computed')
            ->willReturn($item);

        $this->pool->expects(self::once())->method('save')->with($item);

        $result = $manager->remember('computed', fn (): int => 99, 30);

        self::assertSame(99, $result);
    }

    /** @test no tenant → key passes through unmodified for all operations */
    public function testNoTenantKeyPassesThroughUnmodified(): void
    {
        $manager = $this->makeCacheManager(null);

        $this->pool->expects(self::once())
            ->method('deleteItem')
            ->with('bare_key');

        $manager->delete('bare_key');
    }

    public function testCacheKeysAreScopedByTenantName(): void
    {
        $cache = new ArrayAdapter();
        $tenantA = new TenantContext();
        $tenantA->setTenant(1, 'tenant-a', null, null);
        $tenantB = new TenantContext();
        $tenantB->setTenant(2, 'tenant-b', null, null);

        (new CacheManager($cache, $tenantA))->set('shared-key', 'value-a');
        (new CacheManager($cache, $tenantB))->set('shared-key', 'value-b');

        self::assertSame('value-a', (new CacheManager($cache, $tenantA))->get('shared-key'));
        self::assertSame('value-b', (new CacheManager($cache, $tenantB))->get('shared-key'));
    }
    public function testCacheUsesUnscopedKeyWhenTenantIsMissing(): void
    {
        $cache = new ArrayAdapter();
        $manager = new CacheManager($cache, new TenantContext());

        $manager->set('global-key', 'global-value');

        self::assertTrue($manager->has('global-key'));
        self::assertSame('global-value', $manager->get('global-key'));
        self::assertTrue($cache->hasItem('global-key'));
    }
    public function testRememberReusesCachedTenantScopedValue(): void
    {
        $context = new TenantContext();
        $context->setTenant(1, 'acme', null, null);
        $manager = new CacheManager(new ArrayAdapter(), $context);
        $calls = 0;

        self::assertSame('computed', $manager->remember('expensive', function () use (&$calls): string {
            ++$calls;

            return 'computed';
        }));
        self::assertSame('computed', $manager->remember('expensive', function () use (&$calls): string {
            ++$calls;

            return 'other';
        }));
        self::assertSame(1, $calls);
    }
}
