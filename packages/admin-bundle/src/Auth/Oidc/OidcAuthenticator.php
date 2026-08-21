<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

use Nubit\AdminBundle\Auth\CookieFactory;
use Nubit\AdminBundle\Auth\JWTAuthenticationToken;
use Nubit\AdminBundle\Auth\Oidc\Controller\OidcRedirectController;
use Nubit\AdminBundle\Auth\TokenGenerator;
use Nubit\AdminBundle\Auth\TokenPair;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Handles GET /api/auth/oidc/{provider}/callback: exchanges the authorization
 * code (with PKCE) for an ID token, verifies it, resolves an app user, then
 * issues Nubit's own token pair via the same TokenGenerator password login
 * uses — from here on an OIDC-authenticated session is indistinguishable
 * from a password one. This is a browser top-level navigation (the IdP
 * redirected back), not an XHR call, so success/failure both end in a
 * redirect to the app rather than a JSON body.
 */
class OidcAuthenticator extends AbstractAuthenticator
{
    public const string CALLBACK_ROUTE = 'nubit_admin_oidc_callback';

    public function __construct(
        private readonly OidcProviderRegistry $providerRegistry,
        private readonly OidcDiscoveryClient $discoveryClient,
        private readonly IdTokenVerifier $idTokenVerifier,
        private readonly OidcUserResolverInterface $userResolver,
        private readonly OidcFlowStateCodec $flowStateCodec,
        private readonly TokenGenerator $tokenGenerator,
        private readonly CookieFactory $cookieFactory,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    #[Override]
    public function supports(Request $request): ?bool
    {
        return self::CALLBACK_ROUTE === $request->attributes->get('_route');
    }

    #[Override]
    public function authenticate(Request $request): Passport
    {
        $providerName = (string) $request->attributes->get('provider', '');
        $config = $this->providerRegistry->get($providerName);
        if ($config === null) {
            throw new OidcAuthenticationException('Unknown OIDC provider.');
        }

        $flowCookie = $request->cookies->get(OidcRedirectController::FLOW_COOKIE);
        $flowState = is_string($flowCookie) ? $this->flowStateCodec->decode($flowCookie) : null;
        if ($flowState === null) {
            throw new OidcAuthenticationException('Missing or expired OIDC login attempt.');
        }
        if (!hash_equals($flowState->provider, $providerName)) {
            throw new OidcAuthenticationException('OIDC login attempt provider mismatch.');
        }

        $returnedState = $request->query->get('state', '');
        // CSRF: proves this callback is a continuation of a redirect *we*
        // issued, not an attacker's crafted callback URL with their own code.
        if (!hash_equals($flowState->state, $returnedState)) {
            throw new OidcAuthenticationException('OIDC state mismatch.');
        }

        $code = $request->query->get('code');
        if (!is_string($code) || $code === '') {
            $error = $request->query->get('error_description') ?? $request->query->get('error');
            throw new OidcAuthenticationException(
                is_string($error) ? $error : 'OIDC provider did not return an authorization code.',
            );
        }

        $discovery = $this->discoveryClient->discover($config->issuer);
        $idToken = $this->exchangeCodeForIdToken($config, $discovery, $code, $flowState->codeVerifier);
        $claims = $this->idTokenVerifier->verify($idToken, $config, $discovery, $flowState->nonce);
        $user = $this->userResolver->resolve($claims, $config);

        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), fn() => $user));
        $passport->setAttribute('oidc_provider', $config);

        return $passport;
    }

    private function exchangeCodeForIdToken(
        OidcProviderConfig $config,
        OidcDiscoveryDocument $discovery,
        string $code,
        string $codeVerifier,
    ): string {
        try {
            $response = $this->httpClient->request('POST', $discovery->tokenEndpoint, [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $config->redirectUri,
                    'client_id' => $config->clientId,
                    'client_secret' => $config->clientSecret,
                    'code_verifier' => $codeVerifier,
                ],
            ])->toArray();
        } catch (TransportExceptionInterface $e) {
            throw new OidcAuthenticationException('Failed to reach the OIDC token endpoint.', previous: $e);
        } catch (Throwable $e) {
            throw new OidcAuthenticationException('OIDC token endpoint returned an error.', previous: $e);
        }

        if (!isset($response['id_token']) || !is_string($response['id_token']) || $response['id_token'] === '') {
            throw new OidcAuthenticationException('OIDC token response did not include an id_token.');
        }

        return $response['id_token'];
    }

    #[Override]
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();

        try {
            $tokenPair = $this->tokenGenerator->generateTokenPair($user);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate token pair after OIDC login', [
                'username' => $user->getUserIdentifier(),
                'exception' => $e->getMessage(),
            ]);
            throw new AuthenticationException('Could not generate tokens');
        }

        $token = new JWTAuthenticationToken($user, $firewallName, $user->getRoles(), $tokenPair->accessToken);
        $token->setAttribute('tokenPair', $tokenPair);
        $token->setAttribute('oidc_provider', $passport->getAttribute('oidc_provider'));

        return $token;
    }

    #[Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var mixed $tokenPair */
        $tokenPair = $token->getAttribute('tokenPair');
        /** @var mixed $provider */
        $provider = $token->getAttribute('oidc_provider');
        if (!$tokenPair instanceof TokenPair || !$provider instanceof OidcProviderConfig) {
            return $this->failureRedirect($request, 'internal_error');
        }

        $response = new RedirectResponse($provider->postLoginRedirectUri);
        $response->headers->setCookie($this->cookieFactory->createSecureCookie(
            'AUTH_TOKEN',
            $tokenPair->accessToken,
            $tokenPair->accessTokenExpiresAt,
        ));
        $response->headers->setCookie($this->cookieFactory->createSecureCookie(
            'REFRESH_TOKEN',
            $tokenPair->refreshToken,
            $tokenPair->refreshTokenExpiresAt,
        ));
        $response->headers->clearCookie(OidcRedirectController::FLOW_COOKIE, sameSite: Cookie::SAMESITE_LAX);

        return $response;
    }

    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->warning('OIDC authentication failure', [
            'exception' => $exception->getMessage(),
            'provider' => $request->attributes->get('provider'),
        ]);

        return $this->failureRedirect($request, 'oidc_failed');
    }

    private function failureRedirect(Request $request, string $reason): Response
    {
        $providerName = (string) $request->attributes->get('provider', '');
        $config = $this->providerRegistry->get($providerName);
        $target = $config?->postLoginRedirectUri ?? '/';

        $response = new RedirectResponse(
            $target . (str_contains($target, '?') ? '&' : '?') . 'error=' . urlencode($reason),
        );
        $response->headers->clearCookie(OidcRedirectController::FLOW_COOKIE, sameSite: Cookie::SAMESITE_LAX);

        return $response;
    }
}
