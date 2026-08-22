<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\SchemaTool;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\TenantBundle\Entity\Tenant;
use Nubit\TenantBundle\NubitTenantBundle;
use Nubit\TenantBundle\Switcher\PostgresSchemaTenantConnectionSwitcher;
use Nubit\Tests\Integration\Fixture\Entity\Widget;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Schema isolation: one database, one table set per PostgreSQL schema, selected
 * by `search_path`.
 *
 * Nothing in the SQL constrains the rows here — isolation is entirely a
 * property of connection state. That makes the leak modes different from column
 * mode and worth their own suite: a `search_path` that is not switched, or not
 * reset after the response, silently serves another tenant's tables.
 */
#[CoversNothing]
final class SchemaIsolationTest extends IntegrationTestCase
{
    private const string SCHEMA_A = 'tenant_1';
    private const string SCHEMA_B = 'tenant_2';

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
                    'isolation' => 'schema',
                    'resolution' => ['header'],
                    'tenant_entity' => Tenant::class,
                    'schema_prefix' => 'tenant_',
                    'base_schemas' => ['public'],
                ],
            ],
            self::fixtureMapping(),
        );

        $this->provisionSchemas();
    }

    public function testEachTenantReadsItsOwnSchema(): void
    {
        $a = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => '1']);
        $b = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => '2']);

        self::assertSame(['A only'], $this->names($a, self::SCHEMA_A));
        self::assertSame(['B only'], $this->names($b, self::SCHEMA_B));
        self::assertCount(1, $a);
        self::assertCount(1, $b);
    }

    /**
     * The search path is connection state that outlives the request. If the
     * response listener fails to reset it, the next request on a pooled or
     * long-running worker connection — FrankenPHP, Swoole, a Messenger worker —
     * starts inside the previous tenant's schema.
     */
    public function testSearchPathIsResetAfterTheResponse(): void
    {
        $this->request('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => '1']);

        self::assertSame(
            'public',
            $this->currentSearchPath(),
            'The response left the connection pointing at the tenant schema.',
        );
    }

    /**
     * A tenant with no schema of its own resolves against the base schemas
     * instead — `search_path` is a fallback list, not a jail.
     *
     * That is PostgreSQL behaving exactly as documented, and it is also the
     * sharpest edge in this isolation mode: if the base schemas ever hold
     * tenant tables, an unprovisioned tenant reads them without any error. The
     * behaviour is pinned here so it stays a deliberate constraint on
     * `base_schemas` rather than a surprise in production.
     */
    public function testUnprovisionedTenantFallsBackToTheBaseSchema(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DROP TABLE IF EXISTS public.fixture_widget CASCADE');
        $connection->executeStatement(
            'CREATE TABLE public.fixture_widget (id INT PRIMARY KEY, name VARCHAR(120) NOT NULL, tenant_id INT NULL)',
        );
        $connection->executeStatement("INSERT INTO public.fixture_widget VALUES (1, 'base schema row', NULL)");

        $switcher = $this->container()->get(PostgresSchemaTenantConnectionSwitcher::class);
        self::assertInstanceOf(PostgresSchemaTenantConnectionSwitcher::class, $switcher);
        $switcher->switchToTenantId(99);

        $names = $connection->executeQuery('SELECT name FROM fixture_widget')->fetchFirstColumn();

        self::assertSame(
            ['base schema row'],
            $names,
            'An unprovisioned tenant must resolve to the base schema, never to another tenant.',
        );

        $connection->executeStatement('SET search_path TO public');
        $connection->executeStatement('DROP TABLE IF EXISTS public.fixture_widget CASCADE');
    }

    public function testNonPositiveTenantIdIsRejected(): void
    {
        $switcher = $this->container()->get(PostgresSchemaTenantConnectionSwitcher::class);
        self::assertInstanceOf(PostgresSchemaTenantConnectionSwitcher::class, $switcher);

        $this->expectException(\InvalidArgumentException::class);
        $switcher->switchToTenantId(0);
    }

    private function connection(): Connection
    {
        $connection = $this->entityManager()->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function currentSearchPath(): string
    {
        return (string) $this->connection()->executeQuery('SHOW search_path')->fetchOne();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<string>
     */
    private function names(array $ids, string $schema): array
    {
        if ([] === $ids) {
            return [];
        }

        $connection = $this->connection();
        $connection->executeStatement(sprintf('SET search_path TO %s, public', $schema));

        $names = $connection
            ->executeQuery(sprintf('SELECT name FROM fixture_widget WHERE id IN (%s) ORDER BY id', implode(',', $ids)))
            ->fetchFirstColumn();

        $connection->executeStatement('SET search_path TO public');

        return array_values(array_map(strval(...), $names));
    }

    /**
     * Builds the same table set in both tenant schemas and seeds one
     * distinguishable row in each.
     */
    private function provisionSchemas(): void
    {
        $entityManager = $this->entityManager();
        $connection = $this->connection();

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $ddl = (new SchemaTool($entityManager))->getCreateSchemaSql($metadata);

        foreach ([self::SCHEMA_A => 'A only', self::SCHEMA_B => 'B only'] as $schema => $widgetName) {
            $connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $schema));
            $connection->executeStatement(sprintf('CREATE SCHEMA %s', $schema));

            // Unqualified DDL lands in the first schema on the search path.
            $connection->executeStatement(sprintf('SET search_path TO %s', $schema));
            foreach ($ddl as $statement) {
                $connection->executeStatement($statement);
            }

            $connection->executeStatement('INSERT INTO fixture_widget (id, name, tenant_id) VALUES (1, ?, NULL)', [
                $widgetName,
            ]);
        }

        $connection->executeStatement('SET search_path TO public');
    }

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $connection = $this->entityManager()->getConnection();
            foreach ([self::SCHEMA_A, self::SCHEMA_B] as $schema) {
                $connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $schema));
            }
        }

        parent::tearDown();
    }

    /** Keeps the fixture entity referenced so a rename cannot silently orphan this suite. */
    public function testFixtureEntityIsMapped(): void
    {
        self::assertTrue($this->isMapped(Widget::class));
    }
}
