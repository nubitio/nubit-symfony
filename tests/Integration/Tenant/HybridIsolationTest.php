<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\SchemaTool;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\TenantBundle\Doctrine\Connection\DynamicUrlConnection;
use Nubit\TenantBundle\Entity\Tenant;
use Nubit\TenantBundle\NubitTenantBundle;
use Nubit\Tests\Integration\Fixture\Entity\Widget;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Hybrid isolation: the placement is a property of each tenant row, so one
 * deployment serves small tenants from a shared table and large ones from their
 * own database.
 *
 * The risk here is not any single mode — those have their own suites — but the
 * routing between them, and the state one mode leaves behind for the next
 * request that takes a different branch.
 */
#[CoversNothing]
final class HybridIsolationTest extends IntegrationTestCase
{
    private const string DATABASE_B = 'nubit_it_hybrid_b';

    private int $columnTenantId = 0;

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
                    'isolation' => 'hybrid',
                    'resolution' => ['header'],
                    'tenant_entity' => Tenant::class,
                ],
            ],
            self::fixtureMapping(),
        );

        $this->resetSchema();
        $this->provisionTenantDatabase();
        $this->seed();
    }

    /** A column-placed tenant still gets the Doctrine filter, not a connection switch. */
    public function testColumnPlacedTenantReadsTheSharedTable(): void
    {
        $ids = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-column']);

        self::assertSame([1], $ids);
        self::assertSame('integration', $this->currentDatabaseName());
    }

    /** A database-placed tenant is served from its own database in the same deployment. */
    public function testDatabasePlacedTenantReadsItsOwnDatabase(): void
    {
        $ids = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-database']);

        self::assertSame([22], $ids);
    }

    /**
     * Alternating between placements is where hybrid breaks if the response
     * listener resets the wrong thing: a column tenant following a database
     * tenant would read the previous tenant's database with only a `tenant_id`
     * predicate standing between them.
     */
    public function testAlternatingPlacementsDoNotContaminateEachOther(): void
    {
        $database = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-database']);
        $column = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-column']);
        $again = $this->requestIds('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-database']);

        self::assertSame([22], $database);
        self::assertSame([1], $column, 'The column tenant was served from the previous tenant\'s database.');
        self::assertSame([22], $again);
    }

    /**
     * The bundled provider deliberately refuses schema placement in hybrid mode:
     * it cannot know which schema a tenant lives in. Pinning the refusal keeps a
     * misconfigured tenant row from quietly resolving to the base schema.
     */
    public function testSchemaPlacementIsRefusedByTheBundledProvider(): void
    {
        $entityManager = $this->entityManager();
        $tenant = new Tenant();
        $tenant->setName('Tenant S')->setSlug('tenant-schema')->setIsolationMode('schema');
        $entityManager->persist($tenant);
        $entityManager->flush();
        $entityManager->clear();

        $response = $this->requestResponse('/_test/list', ['entity' => 'widget'], ['X-Tenant-Id' => 'tenant-schema']);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString(
            'application-owned TenantIsolationTargetProviderInterface',
            (string) $response->getContent(),
        );
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

    private function provisionTenantDatabase(): void
    {
        $metadata = $this->entityManager()->getMetadataFactory()->getAllMetadata();
        $ddl = (new SchemaTool($this->entityManager()))->getCreateSchemaSql($metadata);

        $base = $this->connection();
        $this->dropDatabase($base, self::DATABASE_B);
        $base->executeStatement(sprintf('CREATE DATABASE %s', self::DATABASE_B));

        $tenantConnection = \Doctrine\DBAL\DriverManager::getConnection((new \Doctrine\DBAL\Tools\DsnParser([
            'postgresql' => 'pdo_pgsql',
        ]))->parse(self::databaseUrlFor(self::DATABASE_B)));

        foreach ($ddl as $statement) {
            $tenantConnection->executeStatement($statement);
        }
        $tenantConnection->executeStatement(
            "INSERT INTO fixture_widget (id, name, tenant_id) VALUES (22, 'widget in own database', NULL)",
        );
        $tenantConnection->close();
    }

    private function seed(): void
    {
        $entityManager = $this->entityManager();

        $column = new Tenant();
        $column->setName('Tenant Column')->setSlug('tenant-column')->setIsolationMode('column');
        $entityManager->persist($column);

        $database = new Tenant();
        $database
            ->setName('Tenant Database')
            ->setSlug('tenant-database')
            ->setIsolationMode('database')
            ->setDatabaseUrl(self::databaseUrlFor(self::DATABASE_B));
        $entityManager->persist($database);

        $entityManager->flush();
        $this->columnTenantId = (int) $column->getId();

        // The shared table holds one row for the column tenant and one for a
        // tenant that is not being queried, so a missing predicate shows up.
        $mine = new Widget();
        $mine->setName('shared row, my tenant')->setTenantId($this->columnTenantId);
        $entityManager->persist($mine);

        $theirs = new Widget();
        $theirs->setName('shared row, other tenant')->setTenantId($this->columnTenantId + 1000);
        $entityManager->persist($theirs);

        $entityManager->flush();
        $entityManager->clear();
    }

    private function dropDatabase(Connection $connection, string $database): void
    {
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
            $this->dropDatabase($connection, self::DATABASE_B);
        }

        parent::tearDown();
    }
}
