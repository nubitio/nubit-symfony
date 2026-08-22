<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Nubit\AdminBundle\Auth\RefreshTokenStoreInterface;
use Nubit\AdminBundle\Identity\Entity\IdentityToken;
use Nubit\AdminBundle\Identity\Event\PasswordResetRequested;
use Nubit\AdminBundle\Identity\Exception\IdentityException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Forgotten passwords.
 *
 * Two properties define the flow, and both are about what it *does not* say:
 *
 *  - the request is answered identically whether or not the account exists, so
 *    the endpoint cannot be used to enumerate customers' staff;
 *  - the token is short-lived, single-use, and hashed at rest, so neither a
 *    leaked database nor a link left in a browser history is a way in.
 *
 * Delivery is an event rather than a mailer call. Which channel a reset goes
 * out on — email, SMS, a helpdesk queue — is an application decision, and
 * wiring a mailer in here would make the module require one.
 */
final readonly class PasswordResetService
{
    public function __construct(
        private IdentityTokenStore $tokens,
        private IdentityUserGatewayInterface $users,
        private AttemptLimiter $limiter,
        private ?EventDispatcherInterface $events = null,
        private ?RefreshTokenStoreInterface $refreshTokens = null,
        private int $lifetimeMinutes = 30,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Starts a reset. Says nothing about whether the account exists.
     *
     * Returns void on purpose: a boolean return would be the first thing a
     * controller leaked back to the caller.
     */
    public function request(string $identifier, string $clientIp = ''): void
    {
        $identifier = trim($identifier);

        if ('' === $identifier) {
            return;
        }

        // Counted before the lookup, so a rate-limited caller cannot learn
        // anything from how long the refusal took.
        if (!$this->limiter->allow('reset:' . strtolower($identifier), 'reset-ip:' . $clientIp)) {
            $this->logger->warning('Password reset rate limit reached.', ['ip' => $clientIp]);

            return;
        }

        $user = $this->users->findByIdentifier($identifier);

        if (null === $user) {
            // Deliberately silent. The caller gets the same answer either way.
            $this->logger->info('Password reset requested for an unknown identifier.');

            return;
        }

        $issued = $this->tokens->issue(
            IdentityToken::PURPOSE_PASSWORD_RESET,
            $user->getUserIdentifier(),
            new \DateInterval('PT' . $this->lifetimeMinutes . 'M'),
        );

        $this->events?->dispatch(
            new PasswordResetRequested($user->getUserIdentifier(), $issued['token'], $issued['record']->getExpiresAt()),
        );
    }

    /**
     * Completes a reset.
     *
     * Every session is revoked afterwards. Someone resetting a password is very
     * often someone who thinks it was stolen, and leaving the thief's refresh
     * token alive would make the reset pointless.
     */
    public function reset(string $token, string $newPassword): void
    {
        $record = $this->tokens->findUsable(IdentityToken::PURPOSE_PASSWORD_RESET, $token);

        if (null === $record) {
            // One message for expired, spent, revoked and never-existed: telling
            // them apart tells an attacker which tokens are worth guessing at.
            throw new IdentityException('This reset link is no longer valid. Request a new one.');
        }

        $user = $this->users->findByIdentifier($record->getSubject());
        if (null === $user) {
            $this->tokens->consume($record);

            throw new IdentityException('This reset link is no longer valid. Request a new one.');
        }

        $this->users->changePassword($user, $newPassword);
        $this->tokens->consume($record);

        $this->refreshTokens?->revokeAllForUser($user->getUserIdentifier());
        $this->limiter->reset('reset:' . strtolower($record->getSubject()));
    }
}
