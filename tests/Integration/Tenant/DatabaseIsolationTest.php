<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\SchemaTool;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\TenantBundle\Doctrine\Connection\DynamicUrlConnection;
use Nubit\TenantBundle\Entity\Tenant;
use Nubit\TenantBundle\NubitTenantBundle;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Database isolation: one physical database per tenant, selected by swapping the
 * connection's URL mid-request.
 *
 * The strongest isolation on offer and the most stateful: the switch mutates a
 * long-lived connection object through reflection. What has to be proven is that
 * the swap happens, that the control plane is still reachable to decide it, and
 * above all that the connection returns to the base database afterwards.
 */
#[CoversNothing]
final class DatabaseIsolationTest extends IntegrationTestCase
{
    private const string DATABASE_A = 'nubit_it_tenant_a';
    private const string DATABASE_B = 'nubit_it_tenant_b';

    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class, NubitTenantBundle::class],
            [
                'doctrine' => [
                    'dbal' => ['wrapper_class' => DynamicUrlConnection::class],
                ],
                'nubit_admin' => [
                    'app_profile' => 'saas',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                ],
                'nubit_tenant' => [
                    'enabled' => true,
                    'isolation' => 'database',
                    'resolution' => ['header'],
                    'tenant_entity' => Tenant::class,
                ],
            ],
            self::fixtureMapping(),
        );

        $this->resetSchema();
        $this->provisionTenantDatabases();
        $this->seedControlPlane();
    }

    public function testEachTenantReadsItsOwnDatabase(): void
    {
        $a = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-a']);
        $b = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-b']);

        // Each tenant database holds a single widget, seeded with a different id
        // so the two results cannot be confused for one another.
        self::assertSame([11], $a);
        self::assertSame([22], $b);
    }

    /**
     * The connection object outlives the request. Leaving it pointed at a tenant
     * database means the next request — or the control-plane lookup that decides
     * which tenant the next request belongs to — runs against the wrong data.
     */
    public function testConnectionReturnsToTheBaseDatabaseAfterTheResponse(): void
    {
        $this->request('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-a']);

        self::assertSame(
            'integration',
            $this->currentDatabaseName(),
            'The response left the connection pointed at the tenant database.',
        );
    }

    /** Two consecutive requests must not bleed into each other through the shared connection. */
    public function testConsecutiveRequestsForDifferentTenantsDoNotBleed(): void
    {
        $first = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-a']);
        $second = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-b']);
        $third = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-a']);

        self::assertSame([11], $first);
        self::assertSame([22], $second);
        self::assertSame([11], $third, 'The third request inherited the second tenant\'s connection.');
    }

    /** A tenant row without a database URL must fail rather than silently serve the control plane. */
    public function testTenantWithoutDatabaseUrlFails(): void
    {
        $entityManager = $this->entityManager();
        $tenant = new Tenant();
        $tenant->setName('Tenant C')->setSlug('tenant-c')->setIsolationMode('database');
        $entityManager->persist($tenant);
        $entityManager->flush();
        $entityManager->clear();

        $response = $this->requestResponse('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-c']);

        // The domain exception is translated into RFC-7807 rather than escaping
        // as a 500 or, far worse, falling through to the control-plane database.
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('No database URL configured', (string) $response->getContent());
    }

    private function connection(): Connection
    {
        $connection = $this->entityManager()->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function currentDatabaseName(): string
    {
        return (string) $this->connection()->executeQuery('SELECT current_database()')->fetchOne();
    }

    /**
     * Creates one database per tenant and builds the fixture schema inside each,
     * with a distinguishable row.
     */
    private function provisionTenantDatabases(): void
    {
        $metadata = $this->entityManager()->getMetadataFactory()->getAllMetadata();
        $ddl = (new SchemaTool($this->entityManager()))->getCreateSchemaSql($metadata);

        $base = $this->connection();

        foreach ([self::DATABASE_A => 11, self::DATABASE_B => 22] as $database => $widgetId) {
            $this->dropDatabase($base, $database);
            // CREATE DATABASE cannot run inside a transaction block; DBAL is in
            // autocommit here, so this is safe as written.
            $base->executeStatement(sprintf('CREATE DATABASE %s', $database));

            $tenantConnection = $this->connectTo($database);
            foreach ($ddl as $statement) {
                $tenantConnection->executeStatement($statement);
            }
            $tenantConnection->executeStatement('INSERT INTO fixture_widget (id, name, tenant_id) VALUES (?, ?, NULL)', [
                $widgetId,
                'widget in ' . $database,
            ]);
            $tenantConnection->close();
        }
    }

    private function seedControlPlane(): void
    {
        $entityManager = $this->entityManager();

        foreach ([
            ['Tenant A', 'tenant-a', self::DATABASE_A],
            ['Tenant B', 'tenant-b', self::DATABASE_B],
        ] as [$name, $slug, $database]) {
            $tenant = new Tenant();
            $tenant
                ->setName($name)
                ->setSlug($slug)
                ->setIsolationMode('database')
                ->setDatabaseUrl(self::databaseUrlFor($database));
            $entityManager->persist($tenant);
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function connectTo(string $database): Connection
    {
        return \Doctrine\DBAL\DriverManager::getConnection((new \Doctrine\DBAL\Tools\DsnParser([
            'postgresql' => 'pdo_pgsql',
        ]))->parse(self::databaseUrlFor($database)));
    }

    private function dropDatabase(Connection $connection, string $database): void
    {
        // Postgres refuses to drop a database that still has sessions attached.
        $connection->executeStatement('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()', [
            $database,
        ]);
        $connection->executeStatement(sprintf('DROP DATABASE IF EXISTS %s', $database));
    }

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $connection = $this->connection();
            if ($connection instanceof DynamicUrlConnection) {
                $connection->resetToBaseUrl();
            }

            foreach ([self::DATABASE_A, self::DATABASE_B] as $database) {
                $this->dropDatabase($connection, $database);
            }
        }

        parent::tearDown();
    }
}
