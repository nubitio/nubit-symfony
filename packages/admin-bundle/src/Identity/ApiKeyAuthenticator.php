<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

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

/**
 * Signs a request in with an `X-Api-Key` header.
 *
 * A separate header from `Authorization` on purpose: a machine credential and a
 * user session are different things with different lifetimes and different
 * revocation stories, and overloading one header makes "which of the two failed"
 * unanswerable in a log.
 *
 * The key resolves to the principal it was issued for, so permissions, row
 * scope and the audit trail all keep working with no special case — an
 * integration is simply a user that never types a password.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public const string HEADER = 'X-Api-Key';

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

        return new SelfValidatingPassport(
            new UserBadge($record->getUserIdentifier(), $this->userProvider->loadUserByIdentifier(...)),
        );
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
