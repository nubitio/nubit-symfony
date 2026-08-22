<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Identity;

use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\Identity\ApiKeyAuthenticator;
use Nubit\AdminBundle\Identity\ApiKeyManager;
use Nubit\AdminBundle\Identity\Entity\IdentityToken;
use Nubit\AdminBundle\Identity\InvitationService;
use Nubit\AdminBundle\Identity\PasswordResetService;
use Nubit\AdminBundle\Identity\TotpManager;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Platform\Identity\Totp;
use Nubit\Tests\Integration\Fixture\Entity\TestUser;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The identity lifecycle over HTTP.
 *
 * Written mostly as abuse cases, because that is what these flows are for. A
 * password reset that works is easy; a password reset that cannot be replayed,
 * cannot be brute-forced and cannot be used to find out who works at the
 * customer is the thing being built.
 */
#[CoversNothing]
final class IdentityLifecycleTest extends IntegrationTestCase
{
    private const string EMAIL = 'admin@example.com';
    private const string PASSWORD = 'secret1234';

    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%', 'cookie_secure' => false],
                    'identity' => [
                        'enabled' => true,
                        'issuer' => 'Acme ERP',
                        'user_class' => TestUser::class,
                        'user_identifier_property' => 'email',
                        'password_reset' => ['max_attempts' => 3, 'window_seconds' => 900],
                    ],
                ],
            ],
            self::fixtureMapping(),
            $this->securityConfig(),
        );

        $this->resetSchema();
        $this->clearAttemptCounters();
        $this->seedUser(self::EMAIL);
    }

    /**
     * The attempt limiter counts in `cache.app`, which lives in the kernel's
     * cache directory — shared by every test booting the same configuration.
     * Without this, a test that exhausts the limit silently starves the ones
     * after it.
     */
    private function clearAttemptCounters(): void
    {
        $cache = $this->container()->get('cache.app');

        if ($cache instanceof \Symfony\Contracts\Cache\CacheInterface && method_exists($cache, 'clear')) {
            $cache->clear();
        }
    }

    // ── Second factor ─────────────────────────────────────────────────────

    public function testEnrolmentIsNotInForceUntilConfirmed(): void
    {
        $totp = $this->totp();
        $totp->beginEnrolment(self::EMAIL);

        // Scanning a QR and closing the tab must not lock anyone out of an
        // account they never finished protecting.
        self::assertFalse($totp->isEnrolled(self::EMAIL));
        self::assertSame(Response::HTTP_OK, $this->login()->getStatusCode());
    }

    public function testConfirmingPutsTheSecondFactorInForce(): void
    {
        $secret = $this->enrol();

        self::assertTrue($this->totp()->isEnrolled(self::EMAIL));

        $withoutCode = $this->login();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $withoutCode->getStatusCode());

        $withCode = $this->login($this->codeFor($secret));
        self::assertSame(Response::HTTP_OK, $withCode->getStatusCode(), (string) $withCode->getContent());
    }

    /**
     * A TOTP code stays valid for its whole window, so an observed one is
     * otherwise replayable for a minute and a half.
     */
    public function testACodeCannotBeUsedTwice(): void
    {
        $secret = $this->enrol();
        $code = $this->codeFor($secret);

        self::assertSame(Response::HTTP_OK, $this->login($code)->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->login($code)->getStatusCode());
    }

    public function testAWrongCodeIsRefused(): void
    {
        $this->enrol();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->login('000000')->getStatusCode());
    }

    /** Brute force: six digits is a million guesses, and every one must fail. */
    public function testGuessedCodesDoNotGetIn(): void
    {
        $secret = $this->enrol();
        $real = $this->codeFor($secret);

        for ($guess = 0; $guess < 25; ++$guess) {
            $candidate = str_pad((string) $guess, 6, '0', \STR_PAD_LEFT);
            if ($candidate === $real) {
                continue;
            }

            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->login($candidate)->getStatusCode());
        }
    }

    public function testARecoveryCodeSignsInAndIsThenSpent(): void
    {
        $enrolment = $this->totp()->beginEnrolment(self::EMAIL);
        $this->totp()->confirmEnrolment(self::EMAIL, Totp::codeAt($enrolment['secret'], intdiv(time(), Totp::PERIOD)));

        $recovery = $enrolment['recoveryCodes'][0];

        self::assertSame(Response::HTTP_OK, $this->login($recovery)->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->login($recovery)->getStatusCode());
    }

    public function testRecoveryCodesAreNotStoredInTheClear(): void
    {
        $enrolment = $this->totp()->beginEnrolment(self::EMAIL);
        $stored = $this->totp()->find(self::EMAIL)?->getRecoveryCodes() ?? [];

        self::assertNotContains($enrolment['recoveryCodes'][0], $stored);
        self::assertCount(10, $stored);
    }

    public function testDisablingRemovesTheSecondFactor(): void
    {
        $this->enrol();
        $this->totp()->disable(self::EMAIL);

        self::assertFalse($this->totp()->isEnrolled(self::EMAIL));
        self::assertSame(Response::HTTP_OK, $this->login()->getStatusCode());
    }

    // ── Password recovery ─────────────────────────────────────────────────

    /**
     * The response must be identical for a known and an unknown address, or the
     * endpoint becomes a way to test whether a person works at the customer.
     */
    public function testTheForgotEndpointSaysNothingAboutWhoExists(): void
    {
        $known = $this->send('POST', '/api/auth/password/forgot', body: ['username' => self::EMAIL]);
        $unknown = $this->send('POST', '/api/auth/password/forgot', body: ['username' => 'nobody@example.com']);

        self::assertSame(Response::HTTP_NO_CONTENT, $known->getStatusCode());
        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        self::assertSame((string) $known->getContent(), (string) $unknown->getContent());
    }

    public function testAResetTokenChangesThePassword(): void
    {
        $token = $this->requestReset();

        $response = $this->send('POST', '/api/auth/password/reset', body: [
            'token' => $token,
            'password' => 'a-brand-new-password',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(Response::HTTP_OK, $this->login(password: 'a-brand-new-password')->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->login()->getStatusCode());
    }

    public function testAResetTokenCannotBeReplayed(): void
    {
        $token = $this->requestReset();

        $this->send('POST', '/api/auth/password/reset', body: ['token' => $token, 'password' => 'first-password']);
        $replay = $this->send('POST', '/api/auth/password/reset', body: [
            'token' => $token,
            'password' => 'second-password',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $replay->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->login(password: 'second-password')->getStatusCode());
    }

    /** Asking for a second link must invalidate the first, not widen the window. */
    public function testRequestingAgainInvalidatesTheEarlierToken(): void
    {
        $first = $this->requestReset();
        $second = $this->requestReset();

        self::assertNotSame($first, $second);

        $stale = $this->send('POST', '/api/auth/password/reset', body: [
            'token' => $first,
            'password' => 'whatever-1234',
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $stale->getStatusCode());
    }

    public function testAnExpiredResetTokenIsRefused(): void
    {
        $token = $this->requestReset();

        $record = $this
            ->entityManager()
            ->getRepository(IdentityToken::class)
            ->findOneBy(['purpose' => IdentityToken::PURPOSE_PASSWORD_RESET]);
        self::assertInstanceOf(IdentityToken::class, $record);

        // Reach past the accessor deliberately: expiry is not settable by
        // design, and the test needs the passage of time without waiting for it.
        $expiry = new \ReflectionProperty($record, 'expiresAt');
        $expiry->setValue($record, new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')));
        $this->entityManager()->flush();

        $response = $this->send('POST', '/api/auth/password/reset', body: [
            'token' => $token,
            'password' => 'whatever-1234',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAGarbageResetTokenIsRefused(): void
    {
        $response = $this->send('POST', '/api/auth/password/reset', body: [
            'token' => str_repeat('a', 64),
            'password' => 'whatever-1234',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /** Limiting only by identity would let one host walk a list of addresses. */
    public function testResetRequestsAreRateLimited(): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->send('POST', '/api/auth/password/forgot', body: ['username' => self::EMAIL]);
        }

        $before = count($this->resetTokens());
        $this->send('POST', '/api/auth/password/forgot', body: ['username' => self::EMAIL]);

        self::assertCount($before, $this->resetTokens(), 'A rate-limited request still issued a token.');
    }

    /** Resetting is very often what somebody does when they think they were robbed. */
    public function testResettingRevokesEverySession(): void
    {
        $this->login();
        $token = $this->requestReset();

        $this->send('POST', '/api/auth/password/reset', body: ['token' => $token, 'password' => 'new-password-99']);

        self::assertSame([], $this->sessionsFor(self::EMAIL));
    }

    // ── Invitations ───────────────────────────────────────────────────────

    public function testAnInvitationCreatesTheAccountOnAcceptance(): void
    {
        $issued = $this->invitations()->invite('newcomer@example.com', ['ROLE_CLERK'], self::EMAIL);

        $response = $this->send('POST', '/api/invitations/' . $issued['token'] . '/accept', body: [
            'password' => 'newcomer-password',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            Response::HTTP_OK,
            $this->login(username: 'newcomer@example.com', password: 'newcomer-password')->getStatusCode(),
        );
    }

    /** The roles are decided at invitation time so the account is never a shell nobody finished. */
    public function testTheInvitedRolesAreGranted(): void
    {
        $issued = $this->invitations()->invite('newcomer@example.com', ['ROLE_CLERK']);
        $this->send('POST', '/api/invitations/' . $issued['token'] . '/accept', body: ['password' => 'pw-12345678']);

        $user = $this->entityManager()->getRepository(TestUser::class)->findOneBy(['email' => 'newcomer@example.com']);
        self::assertInstanceOf(TestUser::class, $user);
        self::assertContains('ROLE_CLERK', $user->getRoles());
    }

    public function testAnInvitationCannotBeAcceptedTwice(): void
    {
        $issued = $this->invitations()->invite('newcomer@example.com');

        $this->send('POST', '/api/invitations/' . $issued['token'] . '/accept', body: ['password' => 'pw-12345678']);
        $again = $this->send('POST', '/api/invitations/' . $issued['token'] . '/accept', body: [
            'password' => 'pw-87654321',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $again->getStatusCode());
    }

    public function testAnExpiredInvitationIsRefused(): void
    {
        $issued = $this->invitations()->invite('newcomer@example.com');

        $expiry = new \ReflectionProperty($issued['record'], 'expiresAt');
        $expiry->setValue($issued['record'], new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')));
        $this->entityManager()->flush();

        $response = $this->send('POST', '/api/invitations/' . $issued['token'] . '/accept', body: [
            'password' => 'pw-12345678',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testARevokedInvitationIsRefused(): void
    {
        $issued = $this->invitations()->invite('newcomer@example.com');
        $this->invitations()->revoke('newcomer@example.com');
        $this->entityManager()->clear();

        $response = $this->send('POST', '/api/invitations/' . $issued['token'] . '/accept', body: [
            'password' => 'pw-12345678',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testInvitingAnExistingAddressIsRefused(): void
    {
        $response = $this->send('POST', '/api/invitations', token: $this->accessToken(), body: [
            'email' => self::EMAIL,
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    /** The preview must not reveal anything for a token that is not valid. */
    public function testPreviewingAnUnknownInvitationIsNotFound(): void
    {
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->send('GET', '/api/invitations/' . str_repeat('b', 64))->getStatusCode(),
        );
    }

    // ── API keys ──────────────────────────────────────────────────────────

    public function testAnApiKeySignsRequestsIn(): void
    {
        $issued = $this->apiKeys()->create('Warehouse scanner', self::EMAIL);

        $response = $this->send('GET', '/api/me', apiKey: $issued['key']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(self::EMAIL, $this->json($response)['username']);
    }

    public function testTheKeyIsNotRecoverableFromTheRecord(): void
    {
        $issued = $this->apiKeys()->create('Warehouse scanner', self::EMAIL);

        self::assertNotSame($issued['key'], $issued['record']->getKeyHash());
        $prefix = $issued['record']->getPrefix();
        self::assertSame(16, strlen($prefix));
        self::assertSame($prefix, substr($issued['key'], 0, 16));
    }

    public function testARevokedKeyStopsWorking(): void
    {
        $issued = $this->apiKeys()->create('Warehouse scanner', self::EMAIL);
        $this->apiKeys()->revoke($issued['record']);

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->send('GET', '/api/me', apiKey: $issued['key'])->getStatusCode(),
        );
    }

    public function testAnExpiredKeyStopsWorking(): void
    {
        $issued = $this->apiKeys()->create(
            'Warehouse scanner',
            self::EMAIL,
            expiresAt: new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')),
        );

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->send('GET', '/api/me', apiKey: $issued['key'])->getStatusCode(),
        );
    }

    /** Rotation must issue and revoke in one step, never leave both alive. */
    public function testRotationRetiresTheOldKey(): void
    {
        $original = $this->apiKeys()->create('Warehouse scanner', self::EMAIL);
        $rotated = $this->apiKeys()->rotate($original['record']);

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->send('GET', '/api/me', apiKey: $original['key'])->getStatusCode(),
        );
        self::assertSame(Response::HTTP_OK, $this->send('GET', '/api/me', apiKey: $rotated['key'])->getStatusCode());
        self::assertSame($original['record']->getName(), $rotated['record']->getName());
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->send('GET', '/api/me', apiKey: 'nbk_' . str_repeat('0', 48))->getStatusCode(),
        );
    }

    public function testUsingAKeyRecordsThatItIsAlive(): void
    {
        $issued = $this->apiKeys()->create('Warehouse scanner', self::EMAIL);
        self::assertNull($issued['record']->getLastUsedAt());

        $this->send('GET', '/api/me', apiKey: $issued['key']);
        $this->entityManager()->clear();

        $reloaded = $this->apiKeys()->all()[0];
        self::assertNotNull($reloaded->getLastUsedAt());
    }

    // ── Sessions ──────────────────────────────────────────────────────────

    public function testSigningInOpensAListableSession(): void
    {
        $this->login();

        $payload = $this->json($this->send('GET', '/api/auth/sessions', token: $this->accessToken()));

        self::assertIsArray($payload['sessions']);
        self::assertNotEmpty($payload['sessions']);
    }

    public function testASessionCanBeClosedIndividually(): void
    {
        $this->login();
        $sessions = $this->sessionsFor(self::EMAIL);
        self::assertNotEmpty($sessions);

        $response = $this->send('DELETE', '/api/auth/sessions/' . $sessions[0], token: $this->accessToken());

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNotContains($sessions[0], $this->sessionsFor(self::EMAIL));
    }

    /**
     * A session id is a small integer. An endpoint that revoked by id alone
     * would let any signed-in user sign out anybody else by counting upwards.
     */
    public function testASessionBelongingToSomebodyElseCannotBeClosed(): void
    {
        $this->seedUser('other@example.com');
        $this->login(username: 'other@example.com');
        $theirSessions = $this->sessionsFor('other@example.com');
        self::assertNotEmpty($theirSessions);

        $this->login();
        $response = $this->send('DELETE', '/api/auth/sessions/' . $theirSessions[0], token: $this->accessToken());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertContains($theirSessions[0], $this->sessionsFor('other@example.com'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function totp(): TotpManager
    {
        $service = $this->container()->get(TotpManager::class);
        self::assertInstanceOf(TotpManager::class, $service);

        return $service;
    }

    private function invitations(): InvitationService
    {
        $service = $this->container()->get(InvitationService::class);
        self::assertInstanceOf(InvitationService::class, $service);

        return $service;
    }

    private function apiKeys(): ApiKeyManager
    {
        $service = $this->container()->get(ApiKeyManager::class);
        self::assertInstanceOf(ApiKeyManager::class, $service);

        return $service;
    }

    private function passwordReset(): PasswordResetService
    {
        $service = $this->container()->get(PasswordResetService::class);
        self::assertInstanceOf(PasswordResetService::class, $service);

        return $service;
    }

    /** @return string the secret, so the test can produce codes */
    private function enrol(): string
    {
        $enrolment = $this->totp()->beginEnrolment(self::EMAIL);
        $this->totp()->confirmEnrolment(self::EMAIL, Totp::codeAt($enrolment['secret'], intdiv(time(), Totp::PERIOD)));

        return $enrolment['secret'];
    }

    /**
     * A code from a later step.
     *
     * Confirming enrolment spends the step it was confirmed with — that is the
     * replay protection doing its job — so a sign-in afterwards uses the next
     * one, exactly as a real user signing in a minute later would.
     */
    private function codeFor(string $secret, int $offset = 1): string
    {
        return Totp::codeAt($secret, intdiv(time(), Totp::PERIOD) + $offset);
    }

    /**
     * Requests a reset and digs the plaintext token out.
     *
     * The application would receive it through the event; the test reads it the
     * same way a mail listener would.
     */
    private function requestReset(): string
    {
        $captured = null;
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class, $dispatcher);

        $listener = static function (\Nubit\AdminBundle\Identity\Event\PasswordResetRequested $event) use (
            &$captured,
        ): void {
            $captured = $event->token;
        };
        $dispatcher->addListener(\Nubit\AdminBundle\Identity\Event\PasswordResetRequested::class, $listener);

        $this->passwordReset()->request(self::EMAIL);

        $dispatcher->removeListener(\Nubit\AdminBundle\Identity\Event\PasswordResetRequested::class, $listener);

        self::assertIsString($captured, 'No reset token was issued.');

        return $captured;
    }

    /** @return list<IdentityToken> */
    private function resetTokens(): array
    {
        $this->entityManager()->clear();

        /** @var list<IdentityToken> $tokens */
        $tokens = $this
            ->entityManager()
            ->getRepository(IdentityToken::class)
            ->findBy([
                'purpose' => IdentityToken::PURPOSE_PASSWORD_RESET,
            ]);

        return $tokens;
    }

    /** @return list<int> */
    private function sessionsFor(string $identifier): array
    {
        $this->entityManager()->clear();

        $registry = $this->container()->get(\Nubit\AdminBundle\Identity\SessionRegistry::class);
        self::assertInstanceOf(\Nubit\AdminBundle\Identity\SessionRegistry::class, $registry);

        return array_map(
            static fn(\Nubit\AdminBundle\Entity\RefreshToken $token): int => (int) $token->getId(),
            $registry->activeFor($identifier),
        );
    }

    private function accessToken(): string
    {
        foreach ($this->login()->headers->getCookies() as $cookie) {
            if (JWTAuthenticator::AUTH_COOKIE === $cookie->getName()) {
                return (string) $cookie->getValue();
            }
        }

        self::fail('Login issued no access token.');
    }

    private function login(
        ?string $totpCode = null,
        string $username = self::EMAIL,
        string $password = self::PASSWORD,
    ): Response {
        $body = ['username' => $username, 'password' => $password];
        if (null !== $totpCode) {
            $body['totpCode'] = $totpCode;
        }

        return $this->send('POST', '/api/auth/login', body: $body);
    }

    private function seedUser(string $email): TestUser
    {
        $hasher = $this->container()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new TestUser();
        $user->setEmail($email)->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $entityManager = $this->entityManager();
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();

        return $user;
    }

    /** @param array<string, mixed> $body */
    private function send(
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        ?string $apiKey = null,
    ): Response {
        $this->entityManager()->clear();

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if (null !== $apiKey) {
            $server['HTTP_X_API_KEY'] = $apiKey;
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
                // Whoever needs these is by definition unable to sign in.
                ['path' => '^/api/auth/(login|refresh|password)', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api/invitations/[^/]+$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api/invitations/[^/]+/accept$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api', 'roles' => 'ROLE_USER'],
            ],
        ];
    }
}
