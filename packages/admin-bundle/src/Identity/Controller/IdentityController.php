<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Identity\ApiKeyManager;
use Nubit\AdminBundle\Identity\Entity\ApiKey;
use Nubit\AdminBundle\Identity\Exception\IdentityException;
use Nubit\AdminBundle\Identity\Exception\TotpException;
use Nubit\AdminBundle\Identity\InvitationService;
use Nubit\AdminBundle\Identity\PasswordResetService;
use Nubit\AdminBundle\Identity\SessionRegistry;
use Nubit\AdminBundle\Identity\TotpManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The identity lifecycle endpoints.
 *
 * Grouped in one controller because they share the same two constraints: they
 * answer without revealing whether an account exists, and the secrets they
 * produce — a TOTP secret, a recovery code, an invitation token, an API key —
 * are readable exactly once, in the response that creates them.
 */
final readonly class IdentityController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
        private TotpManager $totp,
        private PasswordResetService $passwordReset,
        private InvitationService $invitations,
        private ApiKeyManager $apiKeys,
        private SessionRegistry $sessions,
    ) {}

    // ── Second factor ─────────────────────────────────────────────────────

    public function totpBegin(): JsonResponse
    {
        $identifier = $this->currentUserIdentifier();

        try {
            return new JsonResponse($this->totp->beginEnrolment($identifier), Response::HTTP_CREATED);
        } catch (TotpException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    public function totpConfirm(Request $request): JsonResponse
    {
        try {
            $this->totp->confirmEnrolment($this->currentUserIdentifier(), self::field($request, 'code'));
        } catch (TotpException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['enrolled' => true]);
    }

    public function totpStatus(): JsonResponse
    {
        $identifier = $this->currentUserIdentifier();
        $credential = $this->totp->find($identifier);

        return new JsonResponse([
            'enrolled' => $credential?->isConfirmed() ?? false,
            'pending' => null !== $credential && !$credential->isConfirmed(),
            'recoveryCodesLeft' => $credential?->countRecoveryCodes() ?? 0,
        ]);
    }

    public function totpDisable(): JsonResponse
    {
        $this->totp->disable($this->currentUserIdentifier());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function totpRecoveryCodes(): JsonResponse
    {
        try {
            return new JsonResponse([
                'recoveryCodes' => $this->totp->regenerateRecoveryCodes($this->currentUserIdentifier()),
            ]);
        } catch (TotpException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // ── Password recovery ─────────────────────────────────────────────────

    /**
     * Always 204, always.
     *
     * The response must not differ by so much as a status code between a known
     * and an unknown address, or the endpoint becomes a way to test whether a
     * person works at the customer.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $this->passwordReset->request(self::field($request, 'username'), (string) $request->getClientIp());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $this->passwordReset->reset(self::field($request, 'token'), self::field($request, 'password'));
        } catch (IdentityException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['reset' => true]);
    }

    // ── Invitations ───────────────────────────────────────────────────────

    public function invite(Request $request): JsonResponse
    {
        /** @var list<string> $roles */
        $roles = self::arrayField($request, 'roles');

        try {
            $issued = $this->invitations->invite(
                self::field($request, 'email'),
                $roles,
                $this->security->getUser()?->getUserIdentifier(),
            );
        } catch (IdentityException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(
            [
                'email' => $issued['record']->getSubject(),
                'roles' => $issued['record']->getRoles(),
                'expiresAt' => $issued['record']->getExpiresAt()->format(\DATE_ATOM),
                // Returned so an application without a mail listener can still
                // deliver the invitation some other way.
                'token' => $issued['token'],
            ],
            Response::HTTP_CREATED,
        );
    }

    public function previewInvitation(string $token): JsonResponse
    {
        try {
            $record = $this->invitations->preview($token);
        } catch (IdentityException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'email' => $record->getSubject(),
            'roles' => $record->getRoles(),
            'expiresAt' => $record->getExpiresAt()->format(\DATE_ATOM),
        ]);
    }

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        try {
            $user = $this->invitations->accept($token, self::field($request, 'password'));
        } catch (IdentityException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['username' => $user->getUserIdentifier()], Response::HTTP_CREATED);
    }

    // ── API keys ──────────────────────────────────────────────────────────

    public function createApiKey(Request $request): JsonResponse
    {
        /** @var list<string> $roles */
        $roles = self::arrayField($request, 'roles');
        $expires = self::field($request, 'expiresAt');

        try {
            $issued = $this->apiKeys->create(
                self::field($request, 'name'),
                self::field($request, 'username') ?: $this->currentUserIdentifier(),
                $roles,
                '' === $expires ? null : new \DateTimeImmutable($expires, new \DateTimeZone('UTC')),
                $this->security->getUser()?->getUserIdentifier(),
            );
        } catch (IdentityException|\Exception $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(
            [
                ...self::describeKey($issued['record']),
                // The only moment this is readable.
                'key' => $issued['key'],
            ],
            Response::HTTP_CREATED,
        );
    }

    public function listApiKeys(): JsonResponse
    {
        return new JsonResponse(['keys' => array_map(self::describeKey(...), $this->apiKeys->all())]);
    }

    public function rotateApiKey(int $id): JsonResponse
    {
        $issued = $this->apiKeys->rotate($this->apiKey($id), $this->security->getUser()?->getUserIdentifier());

        return new JsonResponse([...self::describeKey($issued['record']), 'key' => $issued['key']]);
    }

    public function revokeApiKey(int $id): JsonResponse
    {
        $this->apiKeys->revoke($this->apiKey($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // ── Sessions ──────────────────────────────────────────────────────────

    public function listSessions(): JsonResponse
    {
        $identifier = $this->currentUserIdentifier();

        return new JsonResponse([
            'sessions' => array_map(fn($session): array => $this->sessions->describe(
                $session,
            ), $this->sessions->activeFor($identifier)),
        ]);
    }

    public function revokeSession(int $id): JsonResponse
    {
        if (!$this->sessions->revoke($this->currentUserIdentifier(), $id)) {
            throw new NotFoundHttpException('Session not found.');
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function describeKey(ApiKey $key): array
    {
        return [
            'id' => $key->getId(),
            'name' => $key->getName(),
            'prefix' => $key->getPrefix(),
            'username' => $key->getUserIdentifier(),
            'roles' => $key->getRoles(),
            'active' => $key->isActive(),
            'expiresAt' => $key->getExpiresAt()?->format(\DATE_ATOM),
            'revokedAt' => $key->getRevokedAt()?->format(\DATE_ATOM),
            'lastUsedAt' => $key->getLastUsedAt()?->format(\DATE_ATOM),
            'createdAt' => $key->getCreatedAt()->format(\DATE_ATOM),
            'createdBy' => $key->getCreatedBy(),
        ];
    }

    private function apiKey(int $id): ApiKey
    {
        $key = $this->entityManager->find(ApiKey::class, $id);

        return $key instanceof ApiKey ? $key : throw new NotFoundHttpException('API key not found.');
    }

    private function currentUserIdentifier(): string
    {
        $user = $this->security->getUser();

        return null === $user ? throw new AccessDeniedHttpException() : $user->getUserIdentifier();
    }

    private static function field(Request $request, string $name): string
    {
        $value = self::body($request)[$name] ?? $request->request->get($name) ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return list<string> */
    private static function arrayField(Request $request, string $name): array
    {
        $value = self::body($request)[$name] ?? $request->request->all()[$name] ?? [];

        if (!is_array($value)) {
            return [];
        }

        $values = [];
        /** @var mixed $entry */
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $values[] = $entry;
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        $body = [];
        /** @var mixed $entry */
        foreach ($decoded as $key => $entry) {
            $body[(string) $key] = $entry;
        }

        return $body;
    }
}
