<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Workflow;

use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Tests\Integration\Fixture\Entity\ScopedUser;
use Nubit\Tests\Integration\Fixture\Entity\Shipment;
use Nubit\Tests\Integration\IntegrationTestCase;
use Nubit\WorkflowBundle\NubitWorkflowBundle;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * `POST {resource}/{id}/transition/{name}` through the whole kernel.
 *
 * The route loads its entity outside API Platform's query path, so it is the
 * one place a caller could name a row `GET` would have hidden. These tests pin
 * that it refuses, alongside the state machine's own rules.
 */
#[CoversNothing]
final class WorkflowTransitionTest extends IntegrationTestCase
{
    private const string PASSWORD = 'secret1234';

    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class, NubitWorkflowBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%', 'cookie_secure' => false],
                ],
            ],
            self::fixtureMapping(),
            $this->securityConfig(),
        );

        $this->resetSchema();
    }

    public function testATransitionWithinScopeAppliesAndPersists(): void
    {
        $id = $this->seedShipment('S-1', warehouse: 1);
        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK'], [1]));

        $response = $this->send('POST', "/api/shipments/{$id}/transition/dispatch", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $shipment = $this->shipment($id);
        self::assertSame('dispatched', $shipment->status);
        self::assertSame('DHL', $shipment->carrier, 'The transition\'s `set` side effects are applied.');
    }

    public function testATransitionCannotReachAForeignRowByItsIdentifier(): void
    {
        $foreign = $this->seedShipment('S-2', warehouse: 2);
        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK'], [1]));

        $response = $this->send('POST', "/api/shipments/{$foreign}/transition/dispatch", $token);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('packed', $this->shipment($foreign)->status, 'The foreign row must not have moved.');
    }

    public function testAnUnscopedUserCanTransitionAnyRow(): void
    {
        $id = $this->seedShipment('S-3', warehouse: 2);
        $token = $this->login($this->seedUser('manager@example.com', ['ROLE_MANAGER'], null));

        $response = $this->send('POST', "/api/shipments/{$id}/transition/dispatch", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testAnEmptyClaimCannotTransitionAnything(): void
    {
        $id = $this->seedShipment('S-4', warehouse: 1);
        $token = $this->login($this->seedUser('new@example.com', ['ROLE_CLERK'], []));

        $response = $this->send('POST', "/api/shipments/{$id}/transition/dispatch", $token);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('packed', $this->shipment($id)->status);
    }

    public function testATransitionFromTheWrongStateIsRefused(): void
    {
        $id = $this->seedShipment('S-5', warehouse: 1, status: 'dispatched');
        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK'], [1]));

        $response = $this->send('POST', "/api/shipments/{$id}/transition/dispatch", $token);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('dispatched', $this->shipment($id)->status);
    }

    public function testATransitionRequiringARoleRefusesACallerWithoutIt(): void
    {
        $id = $this->seedShipment('S-6', warehouse: 1);
        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK'], [1]));

        $response = $this->send('POST', "/api/shipments/{$id}/transition/audit", $token);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('packed', $this->shipment($id)->status);
    }

    public function testATransitionRequiringARoleAllowsACallerWithIt(): void
    {
        $id = $this->seedShipment('S-7', warehouse: 1);
        $token = $this->login($this->seedUser('auditor@example.com', ['ROLE_AUDITOR'], [1]));

        $response = $this->send('POST', "/api/shipments/{$id}/transition/audit", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('audited', $this->shipment($id)->status);
    }

    public function testAnUndeclaredTransitionIsNotFound(): void
    {
        $id = $this->seedShipment('S-8', warehouse: 1);
        $token = $this->login($this->seedUser('clerk@example.com', ['ROLE_CLERK'], [1]));

        $response = $this->send('POST', "/api/shipments/{$id}/transition/teleport", $token);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $id = $this->seedShipment('S-9', warehouse: 1);

        $response = $this->send('POST', "/api/shipments/{$id}/transition/dispatch");

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('packed', $this->shipment($id)->status);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function seedShipment(string $reference, int $warehouse, string $status = 'packed'): int
    {
        $entityManager = $this->entityManager();

        $shipment = new Shipment();
        $shipment->reference = $reference;
        $shipment->warehouse = $warehouse;
        $shipment->status = $status;
        $entityManager->persist($shipment);
        $entityManager->flush();

        $id = (int) $shipment->getId();
        $entityManager->clear();

        return $id;
    }

    private function shipment(int $id): Shipment
    {
        $this->entityManager()->clear();
        $shipment = $this->entityManager()->find(Shipment::class, $id);
        self::assertInstanceOf(Shipment::class, $shipment);

        return $shipment;
    }

    /**
     * @param list<string>   $roles
     * @param list<int>|null $warehouses
     */
    private function seedUser(string $email, array $roles, ?array $warehouses): ScopedUser
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

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
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
    private function securityConfig(): array
    {
        return [
            'password_hashers' => [
                PasswordAuthenticatedUserInterface::class => [
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
