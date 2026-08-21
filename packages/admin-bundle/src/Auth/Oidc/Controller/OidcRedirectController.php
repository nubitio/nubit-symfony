<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc\Controller;

use Nubit\AdminBundle\Auth\CookieFactory;
use Nubit\AdminBundle\Auth\Oidc\OidcDiscoveryClient;
use Nubit\AdminBundle\Auth\Oidc\OidcFlowState;
use Nubit\AdminBundle\Auth\Oidc\OidcFlowStateCodec;
use Nubit\AdminBundle\Auth\Oidc\OidcProviderRegistry;
use Nubit\AdminBundle\Auth\Oidc\Pkce;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/** GET /api/auth/oidc/{provider}/redirect — starts the authorization-code + PKCE flow. */
final readonly class OidcRedirectController
{
    public const string FLOW_COOKIE = 'OIDC_FLOW';

    public function __construct(
        private OidcProviderRegistry $providerRegistry,
        private OidcDiscoveryClient $discoveryClient,
        private OidcFlowStateCodec $flowStateCodec,
        private CookieFactory $cookieFactory,
    ) {
    }

    public function __invoke(string $provider): Response
    {
        $config = $this->providerRegistry->get($provider);
        if ($config === null) {
            return new Response('Unknown OIDC provider.', Response::HTTP_NOT_FOUND);
        }

        $discovery = $this->discoveryClient->discover($config->issuer);

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $codeVerifier = Pkce::generateVerifier();

        $flowState = new OidcFlowState($config->name, $state, $nonce, $codeVerifier, time());

        $authorizationUrl = $discovery->authorizationEndpoint . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $config->clientId,
            'redirect_uri' => $config->redirectUri,
            'scope' => implode(' ', $config->scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => Pkce::challengeFor($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);

        $response = new RedirectResponse($authorizationUrl);
        // SameSite=Lax (not the codebase's usual Strict): the browser sends
        // this cookie on the top-level GET navigation back from the IdP's
        // domain, which Strict would drop — breaking every OIDC redirect flow.
        $response->headers->setCookie($this->cookieFactory->createSecureCookie(
            self::FLOW_COOKIE,
            $this->flowStateCodec->encode($flowState),
            time() + 600,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
