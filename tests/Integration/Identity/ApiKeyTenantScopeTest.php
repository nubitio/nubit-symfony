<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Identity;

use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\Identity\ApiKeyAuthenticator;
use Nubit\AdminBundle\Identity\ApiKeyManager;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\TenantBundle\NubitTenantBundle;
use Nubit\Tests\Integration\Fixture\Entity\TestUser;
use Nubit\Tests\Integration\Fixture\Entity\Widget;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * An API key authenticates as the principal it was issued for — but which
 * *tenant* it acts in must come from the key itself, not from whatever a
 * caller who already holds a valid key claims on top of it.
 *
 * The user in this suite is deliberately not tenant-aware ({@see TestUser}
 * does not implement `TenantAwareUserInterface`, the shape most consuming
 * applications' user classes actually take unless they opt into tenant
 * membership modelling). That is the case where, before the `credential`
 * resolution strategy, an authenticated API-key request resolved *no*
 * tenant at all and the isolation filter never engaged — see
 * `ColumnIsolationTest::testRequestWithoutTenantHeaderIsUnfiltered()` for
 * that behaviour pinned on the header-only path it is designed for.
 */
#[CoversNothing]
final class ApiKeyTenantScopeTest extends IntegrationTestCase
{
    private const string PASSWORD = 'secret1234';

    private string $keyForTenantA = '';
    private int $widgetIdForTenantA = 0;

    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class, NubitTenantBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'saas',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                    'identity' => [
                        'enabled' => true,
                        'issuer' => 'Acme SaaS',
                        'user_class' => TestUser::class,
                        'user_identifier_property' => 'email',
                    ],
                ],
                'nubit_tenant' => [
                    'enabled' => true,
                    'isolation' => 'column',
                    // `header` is included to prove the negative: a caller who
                    // already holds a valid key for tenant A cannot use the
                    // header to additionally claim tenant B. `credential` is
                    // the strategy under test.
                    'resolution' => ['credential', 'header'],
                ],
            ],
            self::fixtureMapping(),
            $this->securityConfig(),
        );

        $this->resetSchema();
        $this->seed();
    }

    public function testAKeyScopesToTheTenantItWasIssuedForWithoutAnyTenantHeader(): void
    {
        $ids = $this->requestIds('/api/_test/list', ['entity' => 'widget'], ['X-Api-Key' => $this->keyForTenantA]);

        self::assertSame(
            [$this->widgetIdForTenantA],
            $ids,
            "A key issued for tenant A must see only tenant A's rows, with no tenant header at all.",
        );
    }

    /**
     * The header strategy stays configured (a browser-facing session login
     * may need it) but a caller authenticated by an API key must not be able
     * to widen — or change — the tenant that key resolves to just by adding
     * a header. `TestUser` is not a tenant member of anything, so this must
     * be refused outright rather than silently honouring either value.
     */
    public function testAnApiKeyCannotBeEscalatedToAnotherTenantViaTheHeader(): void
    {
        $response = $this->requestResponse(
            '/api/_test/list',
            ['entity' => 'widget'],
            ['X-Api-Key' => $this->keyForTenantA, 'X-Tenant-Id' => '2'],
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
            'An authenticated API-key caller claimed a tenant via header instead of via its own credential.',
        );
    }

    /**
     * Even claiming the caller's own tenant through the header is refused:
     * the header is for callers a membership check can verify, and this
     * principal has no modelled membership at all. The credential alone is
     * the source of truth for an API-key request's tenant.
     */
    public function testEvenClaimingItsOwnTenantThroughTheHeaderIsRefused(): void
    {
        $response = $this->requestResponse(
            '/api/_test/list',
            ['entity' => 'widget'],
            ['X-Api-Key' => $this->keyForTenantA, 'X-Tenant-Id' => '1'],
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    private function seed(): void
    {
        $entityManager = $this->entityManager();
        $hasher = $this->container()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new TestUser();
        $user->setEmail('owner@tenant-a.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $entityManager->persist($user);

        $widgetA = new Widget();
        $widgetA->setName('tenant a widget')->setTenantId(1);
        $entityManager->persist($widgetA);

        $widgetB = new Widget();
        $widgetB->setName('tenant b widget')->setTenantId(2);
        $entityManager->persist($widgetB);

        $entityManager->flush();

        $this->widgetIdForTenantA = (int) $widgetA->getId();
        // $widgetB is never read back directly — it exists only so a leaked
        // filter would have a tenant B row to leak.

        $apiKeys = $this->container()->get(ApiKeyManager::class);
        self::assertInstanceOf(ApiKeyManager::class, $apiKeys);

        $issued = $apiKeys->create('tenant a integration key', 'owner@tenant-a.test');
        $issued['record']->setTenantId(1);
        $entityManager->flush();
        $entityManager->clear();

        $this->keyForTenantA = $issued['key'];
    }

    /** @return array<string, mixed> */
    private function securityConfig(): array
    {
        return [
            'password_hashers' => [
                \Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface::class => [
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'app_users' => ['entity' => ['class' => TestUser::class, 'property' => 'email']],
            ],
            'firewalls' => [
                'api' => [
                    'pattern' => '^/api',
                    'stateless' => true,
                    'provider' => 'app_users',
                    'custom_authenticators' => [JWTAuthenticator::class, ApiKeyAuthenticator::class],
                ],
            ],
            'access_control' => [
                ['path' => '^/api', 'roles' => 'ROLE_USER'],
            ],
        ];
    }
}
