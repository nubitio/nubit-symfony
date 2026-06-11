<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Firebase\JWT\ExpiredException;
use Nubit\Platform\Tenant\Context\TenantContext;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Throwable;

/**
 * Dual web/mobile JWT authenticator:
 *
 *  - `Authorization: Bearer <token>` header (mobile / API clients), or
 *  - HttpOnly `AUTH_TOKEN` cookie (web) when no Bearer header is present.
 *
 * On the login route it authenticates `username`/`password` from the JSON
 * body and answers with tokens — as Set-Cookie headers (web) or in the JSON
 * body (`response_mode: json` or `X-Client-Type: android|ios`).
 *
 * Application hooks: TokenClaimsProviderInterface (claims + user payload) and
 * LoginResponseDecoratorInterface (extra cookies on the web response).
 */
class JWTAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const string AUTH_HEADER = 'Authorization';
    public const string AUTH_COOKIE = 'AUTH_TOKEN';
    public const string REFRESH_COOKIE = 'REFRESH_TOKEN';
    public const string LOGIN_ROUTE = 'nubit_admin_auth_login';

    /**
     * @param UserProviderInterface<UserInterface>           $userProvider
     * @param iterable<LoginResponseDecoratorInterface>      $responseDecorators
     */
    public function __construct(
        private readonly UserProviderInterface $userProvider,
        private readonly JWTManagerInterface $jwtManager,
        private readonly TokenGenerator $tokenGenerator,
        private readonly ResponseModeResolver $responseModeResolver,
        private readonly CookieFactory $cookieFactory,
        private readonly LoggerInterface $logger,
        #[AutowireIterator('nubit.admin.login_response_decorator')]
        private readonly iterable $responseDecorators = [],
        private readonly ?TenantContext $tenantContext = null,
    ) {
    }

    #[Override]
    public function supports(Request $request): ?bool
    {
        $isBearerHeaderPresent = $this->hasBearerHeader($request->headers->get(self::AUTH_HEADER));
        $isAuthCookiePresent = $request->cookies->has(self::AUTH_COOKIE);
        $isLoginRoute = self::LOGIN_ROUTE === $request->attributes->get('_route');
        $isPostMethod = $request->isMethod('POST');

        return (($isBearerHeaderPresent || $isAuthCookiePresent) && !$isLoginRoute) || ($isPostMethod && $isLoginRoute);
    }

    #[Override]
    public function authenticate(Request $request): Passport
    {
        // On the login route the user is explicitly supplying credentials —
        // never re-use a JWT that may still be sitting in the browser cookie,
        // otherwise a stale cookie blocks a valid login.
        if (self::LOGIN_ROUTE === $request->attributes->get('_route')) {
            return $this->authenticateWithCredentials($request);
        }

        $jwtToken = $this->extractBearerToken($request->headers->get(self::AUTH_HEADER))
            ?? $request->cookies->get(self::AUTH_COOKIE);

        if (null !== $jwtToken && '' !== $jwtToken) {
            return $this->authenticateWithJWT($jwtToken);
        }

        return $this->authenticateWithCredentials($request);
    }

    private function authenticateWithJWT(string $jwtToken): SelfValidatingPassport
    {
        try {
            $tokenData = $this->jwtManager->decode($jwtToken);
        } catch (ExpiredException $e) {
            $this->logger->warning('JWT token expired', ['exception' => $e->getMessage()]);
            throw new AuthenticationException('Session expired', Response::HTTP_UNAUTHORIZED);
        } catch (Throwable $e) {
            $this->logger->error('Error decoding JWT token', ['exception' => $e->getMessage()]);
            throw new AuthenticationException('Invalid token', Response::HTTP_UNAUTHORIZED);
        }

        $username = $tokenData['username'] ?? null;
        if (!$username) {
            $this->logger->error('JWT token missing username claim');
            throw new AuthenticationException('Invalid token', Response::HTTP_UNAUTHORIZED);
        }

        $tokenTenantName = $tokenData['tenantName'] ?? null;
        $currentTenantName = $this->tenantContext?->getTenantName();
        if (null !== $tokenTenantName && null !== $currentTenantName && $tokenTenantName !== $currentTenantName) {
            $this->logger->warning('JWT tenant mismatch: token belongs to a different tenant', [
                'token_tenant' => $tokenTenantName,
                'current_tenant' => $currentTenantName,
                'username' => $username,
            ]);
            throw new AuthenticationException('Invalid token', Response::HTTP_UNAUTHORIZED);
        }

        $passport = new SelfValidatingPassport(
            new UserBadge($username, $this->userProvider->loadUserByIdentifier(...))
        );
        $passport->setAttribute('is_login', false);
        $passport->setAttribute('token', $jwtToken);

        return $passport;
    }

    private function authenticateWithCredentials(Request $request): Passport
    {
        $username = '';
        $password = '';

        $data = json_decode($request->getContent(), true);
        if (is_array($data)) {
            $username = (string) ($data['username'] ?? '');
            $password = (string) ($data['password'] ?? '');
        }

        if ('' === $username || '' === $password) {
            $this->logger->warning('Login attempt with empty credentials');
            throw new AuthenticationException('Invalid credentials', Response::HTTP_UNAUTHORIZED);
        }

        $userBadge = new UserBadge($username, $this->userProvider->loadUserByIdentifier(...));
        $passport = new Passport($userBadge, new PasswordCredentials($password), [new RememberMeBadge()]);

        if ($this->userProvider instanceof PasswordUpgraderInterface) {
            $passport->addBadge(new PasswordUpgradeBadge($password, $this->userProvider));
        }

        $passport->setAttribute('is_login', true);

        return $passport;
    }

    #[Override]
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        $isLogin = $passport->getAttribute('is_login') ?? true;

        if (!$isLogin) {
            return new JWTAuthenticationToken(
                $user,
                $firewallName,
                $user->getRoles(),
                (string) $passport->getAttribute('token'),
            );
        }

        try {
            $tokenPair = $this->tokenGenerator->generateTokenPair($user);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate token pair', [
                'username' => $user->getUserIdentifier(),
                'exception' => $e->getMessage(),
            ]);
            throw new AuthenticationException('Could not generate tokens');
        }

        $jwtToken = new JWTAuthenticationToken(
            $user,
            $firewallName,
            $user->getRoles(),
            $tokenPair->accessToken,
        );
        $jwtToken->setAttribute('tokenPair', $tokenPair);

        return $jwtToken;
    }

    #[Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if (self::LOGIN_ROUTE !== $request->attributes->get('_route')) {
            return null;
        }

        if (!$token instanceof JWTAuthenticationToken) {
            return null;
        }

        $tokenPair = $token->hasAttribute('tokenPair') ? $token->getAttribute('tokenPair') : null;
        if (!$tokenPair instanceof TokenPair) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }

        if ($this->responseModeResolver->wantsJsonTokens($request)) {
            return new JsonResponse($tokenPair->toArray(includeTokens: true));
        }

        return $this->buildCookieResponse($request, $user, $tokenPair);
    }

    public function buildCookieResponse(Request $request, UserInterface $user, TokenPair $tokenPair): JsonResponse
    {
        $response = new JsonResponse($tokenPair->toArray(includeTokens: false));

        $response->headers->setCookie($this->cookieFactory->createSecureCookie(
            self::AUTH_COOKIE,
            $tokenPair->accessToken,
            $tokenPair->accessTokenExpiresAt,
        ));
        $response->headers->setCookie($this->cookieFactory->createSecureCookie(
            self::REFRESH_COOKIE,
            $tokenPair->refreshToken,
            $tokenPair->refreshTokenExpiresAt,
        ));

        foreach ($this->responseDecorators as $decorator) {
            $decorator->decorate($response, $user, $tokenPair, $request);
        }

        return $response;
    }

    /**
     * @inheritdoc
     */
    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->warning('Authentication failure', [
            'exception' => $exception->getMessage(),
            'ip' => $request->getClientIp() ?? 'unknown',
            'route' => $request->attributes->get('_route'),
        ]);

        return new JsonResponse([
            'message' => $exception->getMessageKey(),
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @inheritdoc
     */
    #[Override]
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse([
            'message' => $authException?->getMessage() ?? 'Authentication Required',
        ], Response::HTTP_UNAUTHORIZED);
    }

    private function hasBearerHeader(?string $authHeader): bool
    {
        return null !== $authHeader && 1 === preg_match('/^\s*Bearer\s+.+$/i', $authHeader);
    }

    private function extractBearerToken(?string $authHeader): ?string
    {
        if (null === $authHeader || 1 !== preg_match('/^\s*Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        return '' === $token ? null : $token;
    }
}
