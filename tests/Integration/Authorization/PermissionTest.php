<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Authorization;

use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\Authorization\Entity\Role;
use Nubit\AdminBundle\Authorization\PermissionCatalog;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Platform\Money\Money;
use Nubit\Tests\Integration\Fixture\Entity\ScopedUser;
use Nubit\Tests\Integration\Fixture\Entity\StockMovement;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Granular permissions, end to end.
 *
 * The acceptance criterion for this work is one sentence: a user without the
 * permission gets a 403 *even when the UI showed them the button*. Everything
 * else here exists to make that sentence true rather than aspirational — the
 * catalogue derived from real operations, the deny-by-default expression, the
 * row scope inside the query, and the amount limit in the voter.
 */
#[CoversNothing]
final class PermissionTest extends IntegrationTestCase
{
    private const string PASSWORD = 'secret1234';

    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%', 'cookie_secure' => false],
                    'authorization' => ['enabled' => true],
                ],
            ],
            self::fixtureMapping(),
            $this->securityConfig(),
        );

        $this->resetSchema();
    }

    // ── The catalogue is derived, not maintained ──────────────────────────

    public function testPermissionsAreDerivedFromTheDeclaredOperations(): void
    {
        $names = $this->catalog()->names();

        // StockMovement declares GetCollection, Get, Post and Delete — so it
        // contributes read, create and delete, and *not* update.
        self::assertContains('movement.read', $names);
        self::assertContains('movement.create', $names);
        self::assertContains('movement.delete', $names);
        self::assertNotContains('movement.update', $names);
    }

    public function testDomainActionsComeFromTheAttribute(): void
    {
        self::assertContains('movement.approve', $this->catalog()->names());
    }

    public function testThePrefixFollowsTheResourceName(): void
    {
        // #[Authorized(resource: 'movement')] overrides the class-derived name.
        self::assertNotContains('stock-movement.read', $this->catalog()->names());
    }

    // ── Deny by default ───────────────────────────────────────────────────

    /**
     * The heart of it. Nothing was declared on the fixture's operations, so
     * without the derived expression the endpoint would be reachable by any
     * authenticated user — which is exactly the state a permissions screen
     * would then misrepresent.
     */
    public function testAUserWithoutThePermissionIsRefused(): void
    {
        $this->seedRole('ROLE_CLERK', ['movement.read']);
        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK']));

        $response = $this->send('POST', '/api/stock_movements', $token, [
            'reference' => 'M-1',
            'warehouse' => 1,
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAUserWithThePermissionIsAllowed(): void
    {
        $this->seedRole('ROLE_CLERK', ['movement.read', 'movement.create']);
        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK']));

        $response = $this->send('POST', '/api/stock_movements', $token, [
            'reference' => 'M-1',
            'warehouse' => 1,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    }

    /** An operation the resource never declared cannot be reached at all. */
    public function testAnUndeclaredOperationIsNotReachable(): void
    {
        $this->seedRole('ROLE_CLERK', ['movement.read', 'movement.update']);
        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK']));
        $movement = $this->seedMovement('M-1', 1, '100.00');

        $response = $this->send('PATCH', '/api/stock_movements/' . $movement, $token, ['reference' => 'M-2']);

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    /** A role holding everything must never be able to lock itself out. */
    public function testASuperRoleHoldsTheWholeCatalogue(): void
    {
        $token = $this->login($this->seedUser('root@example.com', ['ROLE_SUPER_ADMIN']));

        $response = $this->send('POST', '/api/stock_movements', $token, [
            'reference' => 'M-1',
            'warehouse' => 1,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    }

    // ── Row scope ─────────────────────────────────────────────────────────

    public function testAScopedUserSeesOnlyTheirWarehouses(): void
    {
        $this->seedRole('ROLE_CLERK', ['movement.read']);
        $this->seedMovement('M-1', 1, '100.00');
        $this->seedMovement('M-2', 2, '100.00');

        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK'], [1]));

        $payload = $this->json($this->send('GET', '/api/stock_movements', $token));

        self::assertSame(['M-1'], $this->references($payload));
    }

    /**
     * Restricting only the collection would leave every hidden row one guessed
     * identifier away, and would still leak its existence through the total.
     */
    public function testAScopedUserCannotReachAForeignRowByItsIdentifier(): void
    {
        $this->seedRole('ROLE_CLERK', ['movement.read']);
        $foreign = $this->seedMovement('M-2', 2, '100.00');

        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK'], [1]));

        $response = $this->send('GET', '/api/stock_movements/' . $foreign, $token);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAnUnscopedUserSeesEverything(): void
    {
        $this->seedRole('ROLE_MANAGER', ['movement.read']);
        $this->seedMovement('M-1', 1, '100.00');
        $this->seedMovement('M-2', 2, '100.00');

        $token = $this->login($this->seedUser('manager@example.com', ['ROLE_MANAGER'], null));

        self::assertSame(
            ['M-1', 'M-2'],
            $this->references($this->json($this->send('GET', '/api/stock_movements', $token))),
        );
    }

    /**
     * An account nobody finished setting up sees nothing. The other reading —
     * empty means everything — is a silent grant, and silent is the problem.
     */
    public function testAnEmptyClaimSeesNothing(): void
    {
        $this->seedRole('ROLE_CLERK', ['movement.read']);
        $this->seedMovement('M-1', 1, '100.00');

        $token = $this->login($this->seedUser('new@example.com', ['ROLE_CLERK'], []));

        self::assertSame([], $this->references($this->json($this->send('GET', '/api/stock_movements', $token))));
    }

    // ── Amount limits ─────────────────────────────────────────────────────

    public function testAnAmountWithinTheLimitIsApproved(): void
    {
        $this->seedRole(
            'ROLE_SUPERVISOR',
            ['movement.approve'],
            [
                'movement.approve' => ['amount' => '5000.00', 'currency' => 'EUR'],
            ],
        );
        $user = $this->seedUser('sup@example.com', ['ROLE_SUPERVISOR']);
        $movement = $this->movement($this->seedMovement('M-1', 1, '4999.99'));

        self::assertTrue($this->checkerFor($user)->isGranted('movement.approve', $movement));
    }

    public function testAnAmountOverTheLimitIsRefused(): void
    {
        $this->seedRole(
            'ROLE_SUPERVISOR',
            ['movement.approve'],
            [
                'movement.approve' => ['amount' => '5000.00', 'currency' => 'EUR'],
            ],
        );
        $user = $this->seedUser('sup@example.com', ['ROLE_SUPERVISOR']);
        $movement = $this->movement($this->seedMovement('M-1', 1, '5000.01'));

        self::assertFalse($this->checkerFor($user)->isGranted('movement.approve', $movement));
    }

    /** Exactly at the limit is within it — "up to €5,000" includes €5,000. */
    public function testTheLimitIsInclusive(): void
    {
        $this->seedRole(
            'ROLE_SUPERVISOR',
            ['movement.approve'],
            [
                'movement.approve' => ['amount' => '5000.00', 'currency' => 'EUR'],
            ],
        );
        $user = $this->seedUser('sup@example.com', ['ROLE_SUPERVISOR']);
        $movement = $this->movement($this->seedMovement('M-1', 1, '5000.00'));

        self::assertTrue($this->checkerFor($user)->isGranted('movement.approve', $movement));
    }

    /** A credit note is a negative amount; its size is what the limit is about. */
    public function testTheLimitAppliesToTheMagnitudeNotTheSign(): void
    {
        $this->seedRole(
            'ROLE_SUPERVISOR',
            ['movement.approve'],
            [
                'movement.approve' => ['amount' => '5000.00', 'currency' => 'EUR'],
            ],
        );
        $user = $this->seedUser('sup@example.com', ['ROLE_SUPERVISOR']);
        $movement = $this->movement($this->seedMovement('M-1', 1, '-9000.00'));

        self::assertFalse($this->checkerFor($user)->isGranted('movement.approve', $movement));
    }

    /** Comparing across currencies would be a conversion this layer has no rate for. */
    public function testAnAmountInAnotherCurrencyIsRefused(): void
    {
        $this->seedRole(
            'ROLE_SUPERVISOR',
            ['movement.approve'],
            [
                'movement.approve' => ['amount' => '5000.00', 'currency' => 'EUR'],
            ],
        );
        $user = $this->seedUser('sup@example.com', ['ROLE_SUPERVISOR']);
        $movement = $this->movement($this->seedMovement('M-1', 1, '10.00', 'USD'));

        self::assertFalse($this->checkerFor($user)->isGranted('movement.approve', $movement));
    }

    /** Holding the permission through a role with no cap means no cap. */
    public function testAPermissionWithoutALimitIsUnbounded(): void
    {
        $this->seedRole('ROLE_DIRECTOR', ['movement.approve']);
        $user = $this->seedUser('dir@example.com', ['ROLE_DIRECTOR']);
        $movement = $this->movement($this->seedMovement('M-1', 1, '1000000.00'));

        self::assertTrue($this->checkerFor($user)->isGranted('movement.approve', $movement));
    }

    /** Adding a role must feel like adding authority, so the widest cap wins. */
    public function testTheMostPermissiveLimitWinsAcrossRoles(): void
    {
        $this->seedRole(
            'ROLE_SUPERVISOR',
            ['movement.approve'],
            [
                'movement.approve' => ['amount' => '5000.00', 'currency' => 'EUR'],
            ],
        );
        $this->seedRole(
            'ROLE_SENIOR',
            ['movement.approve'],
            [
                'movement.approve' => ['amount' => '20000.00', 'currency' => 'EUR'],
            ],
        );
        $user = $this->seedUser('sup@example.com', ['ROLE_SUPERVISOR', 'ROLE_SENIOR']);
        $movement = $this->movement($this->seedMovement('M-1', 1, '19000.00'));

        self::assertTrue($this->checkerFor($user)->isGranted('movement.approve', $movement));
    }

    // ── The session tells the frontend, and only that ─────────────────────

    public function testTheSessionPublishesTheEffectivePermissions(): void
    {
        $this->seedRole(
            'ROLE_SUPERVISOR',
            ['movement.read', 'movement.approve'],
            [
                'movement.approve' => ['amount' => '5000.00', 'currency' => 'EUR'],
            ],
        );
        $token = $this->login($this->seedUser('sup@example.com', ['ROLE_SUPERVISOR']));

        $payload = $this->json($this->send('GET', '/api/me', $token));

        self::assertSame(['movement.approve', 'movement.read'], $payload['permissions']);
        $limits = $payload['limits'];
        self::assertIsArray($limits);
        self::assertArrayHasKey('movement.approve', $limits);
        $approve = $limits['movement.approve'];
        self::assertIsArray($approve);
        self::assertSame('5000.00', $approve['amount']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function catalog(): PermissionCatalog
    {
        $catalog = $this->container()->get(PermissionCatalog::class);
        self::assertInstanceOf(PermissionCatalog::class, $catalog);

        return $catalog;
    }

    /** Builds a checker whose token is the given user, without going through HTTP. */
    private function checkerFor(ScopedUser $user): AuthorizationCheckerInterface
    {
        $tokenStorage = $this->container()->get('security.token_storage');
        self::assertInstanceOf(
            \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class,
            $tokenStorage,
        );
        $tokenStorage->setToken(
            new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
                $user,
                'api',
                $user->getRoles(),
            ),
        );

        $checker = $this->container()->get('security.authorization_checker');
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $checker);

        return $checker;
    }

    /**
     * @param list<string>                                                       $permissions
     * @param array<string, array{amount: string, currency: string, scale?: int}> $limits
     */
    private function seedRole(string $name, array $permissions, array $limits = []): void
    {
        $entityManager = $this->entityManager();

        $role = new Role();
        $role->setName($name)->setLabel($name)->setPermissions($permissions)->setLimits($limits);
        $entityManager->persist($role);
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @param list<string>   $roles
     * @param list<int>|null $warehouses
     */
    private function seedUser(string $email, array $roles, ?array $warehouses = null): ScopedUser
    {
        $hasher = $this->container()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new ScopedUser();
        $user->setEmail($email)->setRoles($roles)->setWarehouses($warehouses);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $entityManager = $this->entityManager();
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function seedMovement(string $reference, int $warehouse, string $value, string $currency = 'EUR'): int
    {
        $entityManager = $this->entityManager();

        $movement = new StockMovement();
        $movement->reference = $reference;
        $movement->warehouse = $warehouse;
        $movement->setValue(Money::of($value, $currency));
        $entityManager->persist($movement);
        $entityManager->flush();

        $id = (int) $movement->getId();
        $entityManager->clear();

        return $id;
    }

    private function movement(int $id): StockMovement
    {
        $movement = $this->entityManager()->find(StockMovement::class, $id);
        self::assertInstanceOf(StockMovement::class, $movement);

        return $movement;
    }

    private function login(ScopedUser $user): string
    {
        $response = $this->send('POST', '/api/auth/login', null, [
            'username' => $user->getUserIdentifier(),
            'password' => self::PASSWORD,
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        foreach ($response->headers->getCookies() as $cookie) {
            if (JWTAuthenticator::AUTH_COOKIE === $cookie->getName()) {
                return (string) $cookie->getValue();
            }
        }

        self::fail('Login issued no access token.');
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $path, ?string $token = null, array $body = []): Response
    {
        $this->entityManager()->clear();

        $server = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $request = Request::create(
            $path,
            $method,
            [],
            [],
            [],
            $server,
            [] === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        if (null === $this->kernel) {
            self::fail('Boot the kernel before issuing requests.');
        }

        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function references(array $payload): array
    {
        $members = $payload['hydra:member'] ?? $payload['member'] ?? [];
        self::assertIsArray($members);

        $references = [];
        foreach ($members as $member) {
            self::assertIsArray($member);
            self::assertArrayHasKey('reference', $member);
            self::assertIsString($member['reference']);
            $references[] = $member['reference'];
        }

        sort($references);

        return $references;
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
                'app_users' => ['entity' => ['class' => ScopedUser::class, 'property' => 'email']],
            ],
            'firewalls' => [
                'api' => [
                    'pattern' => '^/api',
                    'stateless' => true,
                    'provider' => 'app_users',
                    'custom_authenticator' => JWTAuthenticator::class,
                ],
            ],
            'access_control' => [
                ['path' => '^/api/auth/(login|refresh)', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api/docs', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api', 'roles' => 'ROLE_USER'],
            ],
        ];
    }
}
