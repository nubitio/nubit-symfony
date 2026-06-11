<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Controller;

use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\Auth\RefreshTokenStoreInterface;
use Nubit\AdminBundle\Auth\ResponseModeResolver;
use Nubit\AdminBundle\Auth\TokenGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /api/auth/change-password — `{ "currentPassword": "...", "newPassword": "..." }`.
 *
 * Verifies the current password, stores the new hash through the user
 * provider's PasswordUpgraderInterface, revokes every refresh token of the
 * user (other devices are logged out at access-token expiry), and issues a
 * fresh token pair so the current session continues seamlessly.
 */
final readonly class ChangePasswordController
{
    private const int MIN_PASSWORD_LENGTH = 8;

    /**
     * @param UserProviderInterface<UserInterface> $userProvider
     */
    public function __construct(
        private Security $security,
        private UserPasswordHasherInterface $passwordHasher,
        private UserProviderInterface $userProvider,
        private RefreshTokenStoreInterface $refreshTokenStore,
        private TokenGenerator $tokenGenerator,
        private ResponseModeResolver $responseModeResolver,
        private JWTAuthenticator $authenticator,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->security->getUser();

        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            return $this->error('change_password.unauthenticated', Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $currentPassword = trim((string) ($data['currentPassword'] ?? ''));
        $newPassword = trim((string) ($data['newPassword'] ?? ''));

        if ('' === $currentPassword || '' === $newPassword) {
            return $this->error('change_password.missing_fields', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return $this->error('change_password.too_short', Response::HTTP_UNPROCESSABLE_ENTITY, [
                '%min%' => self::MIN_PASSWORD_LENGTH,
            ]);
        }

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->error('change_password.invalid_current', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->userProvider instanceof PasswordUpgraderInterface) {
            $this->logger->error(
                'Cannot change password: the user provider does not implement PasswordUpgraderInterface.',
            );

            return $this->error('change_password.unavailable', Response::HTTP_NOT_IMPLEMENTED);
        }

        $this->userProvider->upgradePassword($user, $this->passwordHasher->hashPassword($user, $newPassword));

        // Kill every other session's ability to refresh, then re-issue tokens
        // for the current one.
        $this->refreshTokenStore->revokeAllForUser($user->getUserIdentifier());
        $tokenPair = $this->tokenGenerator->generateTokenPair($user);

        $message = $this->translator->trans('change_password.success', [], 'nubit_admin');

        if ($this->responseModeResolver->wantsJsonTokens($request)) {
            return new JsonResponse([...$tokenPair->toArray(includeTokens: true), 'message' => $message]);
        }

        $response = $this->authenticator->buildCookieResponse($request, $user, $tokenPair);
        $response->setData([...$tokenPair->toArray(includeTokens: false), 'message' => $message]);

        return $response;
    }

    /**
     * @param array<string, string|int> $params
     */
    private function error(string $key, int $status, array $params = []): JsonResponse
    {
        return new JsonResponse(
            ['message' => $this->translator->trans($key, $params, 'nubit_admin')],
            $status,
        );
    }
}
