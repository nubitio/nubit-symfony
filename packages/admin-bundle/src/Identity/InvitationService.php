<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Nubit\AdminBundle\Identity\Entity\IdentityToken;
use Nubit\AdminBundle\Identity\Event\UserInvited;
use Nubit\AdminBundle\Identity\Exception\IdentityException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Getting a new colleague into the system.
 *
 * The alternative — an administrator creating the account and telling somebody
 * their password over chat — is how shared credentials start. An invitation
 * means the person who will use the account is the only one who ever knows its
 * password.
 *
 * The roles are decided at invitation time and carried on the token, so the
 * account exists with the right authority from its first second rather than
 * spending a day as an unprivileged shell nobody remembered to finish.
 */
final readonly class InvitationService
{
    public function __construct(
        private IdentityTokenStore $tokens,
        private IdentityUserGatewayInterface $users,
        private ?EventDispatcherInterface $events = null,
        private int $lifetimeDays = 7,
    ) {}

    /**
     * @param list<string> $roles
     *
     * @return array{token: string, record: IdentityToken}
     */
    public function invite(string $email, array $roles = [], ?string $invitedBy = null): array
    {
        $email = strtolower(trim($email));

        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new IdentityException('An invitation needs a valid email address.');
        }

        if (null !== $this->users->findByIdentifier($email)) {
            // Said plainly: an administrator inviting somebody who already has
            // an account is a mistake worth correcting, not a secret to keep.
            // Enumeration is not a concern on an endpoint that already requires
            // the authority to create users.
            throw new IdentityException('That address already has an account.');
        }

        $issued = $this->tokens->issue(
            IdentityToken::PURPOSE_INVITATION,
            $email,
            new \DateInterval('P' . $this->lifetimeDays . 'D'),
            $roles,
            $invitedBy,
        );

        $this->events?->dispatch(
            new UserInvited($email, $issued['token'], $roles, $issued['record']->getExpiresAt(), $invitedBy),
        );

        return $issued;
    }

    /** What the acceptance form needs to show, without revealing anything else. */
    public function preview(string $token): IdentityToken
    {
        return (
            $this->tokens->findUsable(IdentityToken::PURPOSE_INVITATION, $token) ?? throw new IdentityException(
                'This invitation is no longer valid. Ask for a new one.',
            )
        );
    }

    public function accept(string $token, string $password): UserInterface
    {
        $record = $this->preview($token);

        // Re-checked at acceptance, not only at invitation: an account may have
        // been created by other means in the days the invitation was open, and
        // creating a second one would silently split the person in two.
        if (null !== $this->users->findByIdentifier($record->getSubject())) {
            $this->tokens->consume($record);

            throw new IdentityException('That address already has an account. Sign in or reset your password.');
        }

        $user = $this->users->createUser($record->getSubject(), $password, $record->getRoles());
        $this->tokens->consume($record);

        return $user;
    }

    public function revoke(string $email): int
    {
        return $this->tokens->revokeOutstanding(IdentityToken::PURPOSE_INVITATION, strtolower(trim($email)));
    }

    /** @return list<IdentityToken> */
    public function outstanding(): array
    {
        return $this->tokens->outstanding(IdentityToken::PURPOSE_INVITATION);
    }
}
