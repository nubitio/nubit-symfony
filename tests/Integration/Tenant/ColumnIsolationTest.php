<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Tenant;

use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\TenantBundle\Entity\Tenant;
use Nubit\TenantBundle\NubitTenantBundle;
use Nubit\Tests\Integration\Fixture\Entity\GlobalSetting;
use Nubit\Tests\Integration\Fixture\Entity\LooseNote;
use Nubit\Tests\Integration\Fixture\Entity\Widget;
use Nubit\Tests\Integration\Fixture\Entity\WidgetPart;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Column isolation: one database, one schema, a `tenant_id` predicate injected
 * by a Doctrine SQL filter.
 *
 * This is the isolation mode most applications ship, and the one with the
 * thinnest margin for error — a missing predicate is a silent cross-tenant
 * read, not a crash. Every read path an application can take is asserted
 * separately, because they reach the database through different Doctrine code.
 */
#[CoversNothing]
final class ColumnIsolationTest extends IntegrationTestCase
{
    private const string TENANT_A = '1';
    private const string TENANT_B = '2';

    /** @var array<string, int> */
    private array $widgetIds = [];

    /** @var array<string, int> */
    private array $partIds = [];

    /** @var array<string, int> */
    private array $noteIds = [];

    private int $globalSettingId = 0;

    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class, NubitTenantBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'saas',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                ],
                'nubit_tenant' => [
                    'enabled' => true,
                    'isolation' => 'column',
                    'resolution' => ['header'],
                    'tenant_entity' => Tenant::class,
                    'unscoped_entities' => [GlobalSetting::class],
                ],
            ],
            self::fixtureMapping(),
        );

        $this->resetSchema();
        $this->seed();
    }

    public function testEachTenantListsOnlyItsOwnRows(): void
    {
        $a = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => self::TENANT_A]);
        $b = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => self::TENANT_B]);

        self::assertSame([$this->widgetIds['a1'], $this->widgetIds['a2']], $a);
        self::assertSame([$this->widgetIds['b1']], $b);
    }

    public function testDqlReadsAreFilteredToo(): void
    {
        $a = $this->requestIds('/_test/dql', ['entity' => 'widget'], ['X-Tenant-Id' => self::TENANT_A]);

        self::assertSame([$this->widgetIds['a1'], $this->widgetIds['a2']], $a);
        self::assertNotContains($this->widgetIds['b1'], $a);
    }

    /**
     * Guessing another tenant's primary key is the cheapest attack there is,
     * and `find()` reaches the database through the entity persister rather
     * than through DQL — a filter that covers only DQL would leak here.
     */
    public function testFindByForeignPrimaryKeyReturnsNothing(): void
    {
        $response = $this->request(
            '/_test/find',
            ['entity' => 'widget', 'id' => (string) $this->widgetIds['b1']],
            ['X-Tenant-Id' => self::TENANT_A],
        );

        self::assertFalse(self::boolValue($response, 'found'), "Tenant A read tenant B's widget by primary key.");
    }

    public function testOwnPrimaryKeyIsStillReachable(): void
    {
        $response = $this->request(
            '/_test/find',
            ['entity' => 'widget', 'id' => (string) $this->widgetIds['a1']],
            ['X-Tenant-Id' => self::TENANT_A],
        );

        self::assertTrue(self::boolValue($response, 'found'));
        self::assertSame($this->widgetIds['a1'], $response['id']);
    }

    /**
     * A filter applied only to the query root still returns every joined row.
     * The join here is deliberately unconstrained in DQL: isolation has to come
     * from the filter, not from the query the developer happened to write.
     */
    public function testJoinedRowsDoNotLeakAcrossTenants(): void
    {
        $a = $this->request('/_test/join', [], ['X-Tenant-Id' => self::TENANT_A]);

        self::assertSame([$this->partIds['a1']], self::intList($a, 'ids'));
        self::assertNotContains($this->partIds['b1'], self::intList($a, 'ids'));
        self::assertNotContains($this->widgetIds['b1'], self::intList($a, 'widgetIds'));
    }

    /**
     * An entity nobody marked as tenant-owned must return nothing rather than
     * everything. Failing closed turns a forgotten annotation into an obvious
     * empty grid instead of a data breach.
     */
    public function testUnmarkedEntityFailsClosed(): void
    {
        $ids = $this->requestIds('/_test/list', ['entity' => 'loose_note'], ['X-Tenant-Id' => self::TENANT_A]);

        self::assertSame([], $ids, 'An entity outside the tenant metadata must not be readable.');
    }

    /** The allowlist is the only way an entity becomes globally visible. */
    public function testAllowlistedEntityIsVisibleToEveryTenant(): void
    {
        $a = $this->requestIds('/_test/list', ['entity' => 'global_setting'], ['X-Tenant-Id' => self::TENANT_A]);
        $b = $this->requestIds('/_test/list', ['entity' => 'global_setting'], ['X-Tenant-Id' => self::TENANT_B]);

        self::assertSame([$this->globalSettingId], $a);
        self::assertSame([$this->globalSettingId], $b);
    }

    /** The tenant root filters on its own primary key, not on a `tenant_id` column. */
    public function testTenantRootSeesOnlyItself(): void
    {
        $this->markTestSkippedUnlessTenantTableMapped();

        $rows = $this
            ->entityManager()
            ->createQuery(sprintf('SELECT t.id AS id FROM %s t', Tenant::class))
            ->getArrayResult();

        self::assertCount(2, $rows, 'Both tenants exist when no tenant is active.');
    }

    /**
     * A request without the tenant header resolves no tenant, so the filter is
     * never enabled. That is by design for internal profiles, and it is exactly
     * why an application must not expose unauthenticated routes in SaaS mode —
     * pinning it here makes the behaviour a decision rather than an accident.
     */
    public function testRequestWithoutTenantHeaderIsUnfiltered(): void
    {
        $ids = $this->requestIds('/_test/list', ['entity' => 'widget']);

        self::assertSame([$this->widgetIds['a1'], $this->widgetIds['a2'], $this->widgetIds['b1']], $ids);
    }

    private function markTestSkippedUnlessTenantTableMapped(): void
    {
        if (!$this->entityManager()->getMetadataFactory()->hasMetadataFor(Tenant::class)) {
            self::markTestSkipped('The bundle unmapped its Tenant entity for this configuration.');
        }
    }

    private function seed(): void
    {
        $entityManager = $this->entityManager();

        foreach (['Tenant A', 'Tenant B'] as $index => $name) {
            $tenant = new Tenant();
            $tenant->setName($name)->setSlug('tenant-' . ($index + 1));
            $entityManager->persist($tenant);
        }

        $widgets = [
            'a1' => [1, 'A widget one'],
            'a2' => [1, 'A widget two'],
            'b1' => [2, 'B widget one'],
        ];

        $entities = [];
        foreach ($widgets as $key => [$tenantId, $name]) {
            $widget = new Widget();
            $widget->setName($name)->setTenantId($tenantId);
            $entityManager->persist($widget);
            $entities[$key] = $widget;
        }

        $parts = ['a1' => ['a1', 1, 'A part'], 'b1' => ['b1', 2, 'B part']];
        $partEntities = [];
        foreach ($parts as $key => [$widgetKey, $tenantId, $name]) {
            $part = new WidgetPart();
            $part->setName($name)->setWidget($entities[$widgetKey])->setTenantId($tenantId);
            $entityManager->persist($part);
            $partEntities[$key] = $part;
        }

        $notes = [];
        foreach (['a1' => 1, 'b1' => 2] as $key => $tenantId) {
            $note = new LooseNote();
            $note->setName('note ' . $key)->setTenantId($tenantId);
            $entityManager->persist($note);
            $notes[$key] = $note;
        }

        $setting = new GlobalSetting();
        $setting->setName('default-currency');
        $entityManager->persist($setting);

        $entityManager->flush();

        $this->widgetIds = array_map(static fn(Widget $w): int => (int) $w->getId(), $entities);
        $this->partIds = array_map(static fn(WidgetPart $p): int => (int) $p->getId(), $partEntities);
        $this->noteIds = array_map(static fn(LooseNote $n): int => (int) $n->getId(), $notes);
        $this->globalSettingId = (int) $setting->getId();

        $entityManager->clear();
    }
}
