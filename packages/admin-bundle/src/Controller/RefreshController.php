<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Controller;

use Nubit\AdminBundle\Auth\JWTAuthenticator;
use Nubit\AdminBundle\Auth\JWTManagerInterface;
use Nubit\AdminBundle\Auth\RefreshTokenStoreInterface;
use Nubit\AdminBundle\Auth\ResponseModeResolver;
use Nubit\AdminBundle\Auth\TokenGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * POST /api/auth/refresh — rotates a refresh token.
 *
 * Web clients send the HttpOnly REFRESH_TOKEN cookie; mobile clients send
 * `{ "refreshToken": "..." }` in the body and receive new tokens in the body.
 */
final readonly class RefreshController
{
    /**
     * @param UserProviderInterface<UserInterface> $userProvider
     */
    public function __construct(
        private JWTManagerInterface $jwtManager,
        private RefreshTokenStoreInterface $refreshTokenStore,
        private TokenGenerator $tokenGenerator,
        private UserProviderInterface $userProvider,
        private ResponseModeResolver $responseModeResolver,
        private JWTAuthenticator $authenticator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);
        $refreshToken = (is_array($body) ? ($body['refreshToken'] ?? null) : null)
            ?? $request->cookies->get(JWTAuthenticator::REFRESH_COOKIE);

        if (!$refreshToken) {
            return new JsonResponse(['message' => 'No refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $this->jwtManager->decode($refreshToken);
        } catch (Throwable $e) {
            $this->logger->warning('Invalid refresh token', ['exception' => $e->getMessage()]);

            return new JsonResponse(['message' => 'Invalid refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        $validationError = $this->validatePayload($payload);
        if (null !== $validationError) {
            return $validationError;
        }

        try {
            $user = $this->userProvider->loadUserByIdentifier((string) $payload['username']);
        } catch (Throwable) {
            $this->logger->warning('User not found for refresh token', [
                'username' => $payload['username'] ?? null,
            ]);

            return new JsonResponse(['message' => 'Invalid refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        $tokenHash = hash('sha256', (string) $refreshToken);
        if (!$this->refreshTokenStore->isActiveByHash($tokenHash)) {
            $this->logger->warning('Refresh token not active in store', [
                'username' => $user->getUserIdentifier(),
            ]);

            return new JsonResponse(['message' => 'Invalid refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        // Rotation: the presented token is single-use.
        $this->refreshTokenStore->revokeByHash($tokenHash);

        try {
            $tokenPair = $this->tokenGenerator->generateTokenPair($user, $payload);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate new token pair during refresh', [
                'username' => $user->getUserIdentifier(),
                'exception' => $e->getMessage(),
            ]);

            return new JsonResponse(['message' => 'Error generating tokens'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($this->responseModeResolver->wantsJsonTokens($request)) {
            return new JsonResponse($tokenPair->toArray(includeTokens: true));
        }

        return $this->authenticator->buildCookieResponse($request, $user, $tokenPair);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validatePayload(array $payload): ?JsonResponse
    {
        if ('refresh' !== ($payload['type'] ?? null)) {
            return new JsonResponse(['message' => 'Invalid refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return new JsonResponse(['message' => 'Expired refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        if (!isset($payload['jti']) || !isset($payload['username'])) {
            return new JsonResponse(['message' => 'Invalid refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }
}
