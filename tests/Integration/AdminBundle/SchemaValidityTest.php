<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\AdminBundle;

use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\TenantBundle\Entity\Tenant;
use Nubit\TenantBundle\NubitTenantBundle;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The mapping the bundles ship must describe a schema PostgreSQL will accept,
 * and one that stays in sync once created.
 *
 * Broken mapping is normally discovered by whoever runs
 * `doctrine:migrations:diff` in a downstream application — which is to say,
 * after release. Both halves are checked: `validateMapping()` catches
 * inconsistent associations, and diffing a freshly created schema against the
 * metadata catches column definitions the database silently reshapes.
 */
#[CoversNothing]
final class SchemaValidityTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class, NubitTenantBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'saas',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                    'audit' => ['enabled' => true],
                    'media' => ['enabled' => true],
                    'notification' => ['enabled' => true, 'in_app' => ['enabled' => true]],
                    'analytics' => ['enabled' => true],
                ],
                'nubit_tenant' => [
                    'enabled' => true,
                    'isolation' => 'column',
                    'tenant_entity' => Tenant::class,
                ],
            ],
            self::fixtureMapping(),
        );
    }

    public function testMappingIsValid(): void
    {
        $errors = (new SchemaValidator($this->entityManager()))->validateMapping();

        self::assertSame([], $errors, $this->describe($errors));
    }

    /**
     * A schema created from the metadata must produce no further changes when
     * diffed against that same metadata. A difference here means the mapping
     * says one thing and the generated DDL another, and every downstream
     * application inherits a migration that never settles.
     */
    public function testCreatedSchemaIsInSyncWithTheMapping(): void
    {
        $entityManager = $this->entityManager();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $pending = $schemaTool->getUpdateSchemaSql($metadata);

        self::assertSame(
            [],
            $pending,
            "The schema is out of sync immediately after creation:\n" . implode("\n", $pending),
        );
    }

    /** @param array<string, list<string>> $errors */
    private function describe(array $errors): string
    {
        $lines = [];
        foreach ($errors as $class => $messages) {
            foreach ($messages as $message) {
                $lines[] = sprintf('%s: %s', $class, $message);
            }
        }

        return implode("\n", $lines);
    }
}
