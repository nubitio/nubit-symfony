<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nubit\TenantBundle\Doctrine\Filter\TenantFilter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for tests that need a compiled container and a real database.
 *
 * PostgreSQL, not SQLite: the code under test uses Doctrine SQL filters,
 * PostgreSQL schemas, `search_path` switching and `pg_dump`. A portable
 * in-memory database would pass while the production path stays unverified,
 * which is worse than having no test at all.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected ?TestKernel $kernel = null;

    /**
     * Absent locally, the suite skips so `composer test` keeps working without
     * Docker. Absent in CI, it fails — a silently skipped isolation suite is
     * indistinguishable from a passing one on the dashboard, and that is the
     * exact failure mode this plan exists to remove.
     */
    public static function databaseUrl(): string
    {
        $url = getenv('NUBIT_TEST_DATABASE_URL');

        if (!is_string($url) || '' === trim($url)) {
            $ci = getenv('CI');
            if (is_string($ci) && '' !== $ci && 'false' !== $ci) {
                self::fail('NUBIT_TEST_DATABASE_URL must be set in CI: integration tests may not be skipped there.');
            }

            self::markTestSkipped(
                'Set NUBIT_TEST_DATABASE_URL to run integration tests (scripts/integration-tests.sh does it for you).',
            );
        }

        return $url;
    }

    /**
     * URL of a sibling database on the same server, for the isolation modes
     * that put each tenant in its own database.
     *
     * Built from the discrete connection variables rather than by rewriting the
     * URL, so a password containing a slash cannot corrupt the result.
     */
    protected static function databaseUrlFor(string $database): string
    {
        self::databaseUrl();

        $host = (string) getenv('NUBIT_TEST_DATABASE_HOST');
        $user = (string) getenv('NUBIT_TEST_DATABASE_USER');
        $password = (string) getenv('NUBIT_TEST_DATABASE_PASSWORD');

        if ('' === $host || '' === $user) {
            self::markTestSkipped('NUBIT_TEST_DATABASE_HOST and NUBIT_TEST_DATABASE_USER are required.');
        }

        return sprintf(
            'postgresql://%s:%s@%s:5432/%s?serverVersion=16&charset=utf8',
            rawurlencode($user),
            rawurlencode($password),
            $host,
            $database,
        );
    }

    /**
     * @param list<class-string<\Symfony\Component\HttpKernel\Bundle\BundleInterface>> $bundles
     * @param array<string, array<string, mixed>>                                      $extensionConfig
     * @param array<string, array{dir: string, prefix: string}>                        $entityMappings
     * @param array<string, mixed>|null                                                $securityConfig
     */
    protected function boot(
        array $bundles,
        array $extensionConfig,
        array $entityMappings = [],
        ?array $securityConfig = null,
    ): TestKernel {
        $this->kernel = new TestKernel(
            $bundles,
            $extensionConfig,
            $entityMappings,
            self::databaseUrl(),
            $securityConfig,
        );
        $this->kernel->boot();

        return $this->kernel;
    }

    /**
     * Fixture entities live outside any bundle, so tests map them by path.
     *
     * @return array<string, array{dir: string, prefix: string}>
     */
    protected static function fixtureMapping(): array
    {
        return [
            'NubitTestFixtures' => [
                'dir' => __DIR__ . '/Fixture/Entity',
                'prefix' => 'Nubit\\Tests\\Integration\\Fixture\\Entity',
            ],
        ];
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->container()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * Whether Doctrine was configured to map this class.
     *
     * Asked of the mapping driver, not of `hasMetadataFor()`: that one reports
     * whether metadata happens to be *loaded*, which under DoctrineBundle 2 was
     * true for everything only because the proxy cache warmer had walked every
     * class on boot. DoctrineBundle 3 generates no proxies and runs no warmer,
     * so the same call answers false for classes that are mapped perfectly well.
     * The question these suites mean to ask is about configuration.
     */
    protected function isMapped(string $entityClass): bool
    {
        $driver = $this->entityManager()->getConfiguration()->getMetadataDriverImpl();
        self::assertNotNull($driver, 'Doctrine has no metadata driver.');

        return in_array($entityClass, $driver->getAllClassNames(), true);
    }

    protected function container(): ContainerInterface
    {
        if (null === $this->kernel) {
            self::fail('Boot the kernel before using the container.');
        }

        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /** Rebuilds the schema from mapping metadata — every test starts from bare tables. */
    protected function resetSchema(): void
    {
        $entityManager = $this->entityManager();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    /**
     * Issues a request through the full kernel: tenant resolution, listeners,
     * filter application and the controller.
     *
     * The identity map is cleared first. Doctrine would otherwise answer a
     * second `find()` from memory and the test would assert on a cached object
     * rather than on what the database was willing to return.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    protected function request(string $path, array $query = [], array $headers = []): array
    {
        $response = $this->requestResponse($path, $query, $headers);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            sprintf('Request to %s failed: %s', $path, (string) $response->getContent()),
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Same pipeline, without asserting a successful status — for the cases where
     * the refusal is the behaviour under test.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    protected function requestResponse(string $path, array $query = [], array $headers = []): Response
    {
        $this->entityManager()->clear();

        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        $request = Request::create($path, 'GET', $query, [], [], $server);

        if (null === $this->kernel) {
            self::fail('Boot the kernel before issuing requests.');
        }

        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        // The request listener enables the filter on the shared entity manager
        // and leaves it enabled. Seeding or asserting afterwards must not
        // inherit the previous request's tenant.
        $filters = $this->entityManager()->getFilters();
        if ($filters->isEnabled(TenantFilter::NAME)) {
            $filters->disable(TenantFilter::NAME);
        }

        return $response;
    }

    /**
     * Reads an integer-list key out of a response. Tests assert on identifiers
     * constantly, and going through a typed accessor keeps the assertions off
     * `mixed` — the analyzer is right that an untyped JSON payload proves
     * nothing about what was compared.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     *
     * @return list<int>
     */
    protected function requestIds(string $path, array $query = [], array $headers = [], string $key = 'ids'): array
    {
        return self::intList($this->request($path, $query, $headers), $key);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<int>
     */
    protected static function intList(array $payload, string $key): array
    {
        self::assertArrayHasKey($key, $payload);
        $values = $payload[$key];
        self::assertIsArray($values);

        $ids = [];
        foreach ($values as $value) {
            self::assertIsInt($value);
            $ids[] = $value;
        }

        return $ids;
    }

    /** @param array<string, mixed> $payload */
    protected static function stringValue(array $payload, string $key): string
    {
        self::assertArrayHasKey($key, $payload);
        $value = $payload[$key];
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    protected static function rowList(array $payload, string $key): array
    {
        self::assertArrayHasKey($key, $payload);
        $rows = $payload[$key];
        self::assertIsArray($rows);

        $result = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $typed = [];
            /** @var mixed $value */
            foreach ($row as $field => $value) {
                $typed[(string) $field] = $value;
            }
            $result[] = $typed;
        }

        return $result;
    }

    /** @param array<string, mixed> $payload */
    protected static function boolValue(array $payload, string $key): bool
    {
        self::assertArrayHasKey($key, $payload);
        $value = $payload[$key];
        self::assertIsBool($value);

        return $value;
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

        parent::tearDown();

        // Booting the kernel installs Symfony's error handler and never removes
        // it. PHPUnit reports the leftover as leaked global state, so each boot
        // is unwound here rather than silencing the check.
        $this->restoreHandlers();
    }

    private function restoreHandlers(): void
    {
        // One boot per test installs one exception handler. The error handler
        // is left alone: FrameworkBundle replaces the existing one in place
        // rather than stacking a new one, so restoring it would pop a handler
        // this test never installed.
        restore_exception_handler();
    }
}
