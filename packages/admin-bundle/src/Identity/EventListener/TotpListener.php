<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\EventListener;

use Nubit\AdminBundle\Identity\Badge\TotpBadge;
use Nubit\AdminBundle\Identity\Exception\TotpException;
use Nubit\AdminBundle\Identity\Exception\TotpRequiredException;
use Nubit\AdminBundle\Identity\TotpManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Checks the second factor, after the password.
 *
 * The priority is the design. Symfony's credentials listener runs at 128; this
 * runs below it, so by the time it fires the password is known to be correct.
 * Checking earlier would let anyone with a username discover whether the
 * account has a second factor, and — worse — would let a wrong password reach
 * the TOTP code path at all.
 */
final readonly class TotpListener implements EventSubscriberInterface
{
    public function __construct(
        private TotpManager $totp,
    ) {}

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [CheckPassportEvent::class => ['onCheckPassport', 0]];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        $badge = $passport->hasBadge(TotpBadge::class) ? $passport->getBadge(TotpBadge::class) : null;
        if (!$badge instanceof TotpBadge) {
            return;
        }

        // Every path that does not throw resolves the badge. An unresolved
        // badge fails the whole passport, so forgetting one here would lock out
        // every user who does *not* use a second factor — the opposite of what
        // this listener is for.

        // Only the credentials path. A request authenticated by an existing JWT
        // already presented its second factor to obtain that JWT.
        if (true !== $passport->getAttribute('is_login')) {
            $badge->markResolved();

            return;
        }

        $user = $passport->getUser();

        if (!$this->totp->isRequiredFor($user)) {
            $badge->markResolved();

            return;
        }

        $identifier = $user->getUserIdentifier();

        if (!$this->totp->isEnrolled($identifier)) {
            // Required by policy but not enrolled. Refusing outright would leave
            // the user unable to enrol at all; the caller is told what is
            // missing so it can route them into enrolment.
            throw new CustomUserMessageAuthenticationException('Second factor enrolment required.');
        }

        if ('' === trim($badge->getCode())) {
            throw new TotpRequiredException();
        }

        try {
            $this->totp->verify($identifier, $badge->getCode());
        } catch (TotpException $exception) {
            throw new CustomUserMessageAuthenticationException($exception->getMessage(), previous: $exception);
        }

        $badge->markResolved();
    }
}
