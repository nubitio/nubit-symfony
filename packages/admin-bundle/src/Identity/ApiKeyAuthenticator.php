<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Nubit\Platform\Tenant\Http\TenantCredentialRequestAttribute;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Signs a request in with an `X-Api-Key` header.
 *
 * A separate header from `Authorization` on purpose: a machine credential and a
 * user session are different things with different lifetimes and different
 * revocation stories, and overloading one header makes "which of the two failed"
 * unanswerable in a log.
 *
 * The key resolves to the principal it was issued for, so row scope and the
 * audit trail all keep working with no special case — an integration is
 * simply a user that never types a password. Permissions are the exception:
 * a key carrying an explicit role scope authenticates as the intersection of
 * that scope and the principal's own roles, never the principal's full grant.
 * A key with no declared scope inherits the principal unrestricted, which is
 * never wider than the principal already is.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public const string HEADER = 'X-Api-Key';

    private const string SCOPE_ATTRIBUTE = 'api_key_roles';

    /** @param UserProviderInterface<\Symfony\Component\Security\Core\User\UserInterface> $userProvider */
    public function __construct(
        private readonly ApiKeyManager $keys,
        private readonly UserProviderInterface $userProvider,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has(self::HEADER);
    }

    public function authenticate(Request $request): Passport
    {
        $presented = (string) $request->headers->get(self::HEADER, '');

        $record = $this->keys->resolve($presented);

        if (null === $record) {
            // One message for unknown, expired and revoked. Telling them apart
            // tells whoever is probing which keys are worth probing further.
            throw new CustomUserMessageAuthenticationException('Invalid API key.');
        }

        // Published unconditionally, whether or not tenant-bundle is even
        // installed: a request attribute nobody reads costs nothing, and a
        // key minted inside one tenant must resolve to that tenant on every
        // request it authenticates, not to whatever a header or subdomain
        // happens to claim.
        $request->attributes->set(TenantCredentialRequestAttribute::NAME, $record->getTenantId());

        $passport = new SelfValidatingPassport(
            new UserBadge($record->getUserIdentifier(), $this->userProvider->loadUserByIdentifier(...)),
        );

        // Carried through to createToken() rather than read off the entity
        // again there: the passport, not the authenticator instance, is what
        // is guaranteed to belong to this one request.
        $passport->setAttribute(self::SCOPE_ATTRIBUTE, $record->getRoles());

        return $passport;
    }

    /**
     * Restricts the token to the key's declared scope.
     *
     * An empty scope means the key was issued without a restriction and
     * inherits the principal as-is — never wider than the principal already
     * is. A non-empty scope narrows the principal's roles to their
     * intersection with the key's roles, computed fresh on every request so
     * a role revoked from the principal after the key was issued is revoked
     * from the key too.
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();

        /** @var list<string> $scope */
        $scope = $passport->getAttribute(self::SCOPE_ATTRIBUTE, []);

        $roles = $user->getRoles();
        if ([] !== $scope) {
            // ROLE_USER marks "authenticated", not a granted permission —
            // access_control and the rest of the permission model both
            // assume it is present, so a scope narrows what the key may *do*
            // without being able to narrow away the fact that it is signed in.
            $roles = array_values(array_unique([...array_intersect($roles, $scope), 'ROLE_USER']));
        }

        return new PostAuthenticationToken($user, $firewallName, $roles);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['message' => 'Invalid API key.'], Response::HTTP_UNAUTHORIZED);
    }
}
