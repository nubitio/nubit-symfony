<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Entity\RefreshToken;

/**
 * The sessions a user currently has open, and the ability to close one.
 *
 * Built on the refresh tokens that already exist rather than on a new table: a
 * refresh token *is* a session — it is the thing that keeps an account signed
 * in — and a second list beside it would be a list that disagrees.
 *
 * Individual revocation is the point. "Change your password to sign out
 * everywhere" is the advice given when there is no session list, and it makes
 * losing one device cost every other one.
 */
final readonly class SessionRegistry
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /** @return list<RefreshToken> Newest first. */
    public function activeFor(string $userIdentifier): array
    {
        /** @var list<RefreshToken> $sessions */
        $sessions = $this->entityManager
            ->createQueryBuilder()
            ->select('rt')
            ->from(RefreshToken::class, 'rt')
            ->where('rt.userIdentifier = :user')
            ->andWhere('rt.revokedAt IS NULL')
            ->andWhere('rt.expiresAt > :now')
            ->setParameter('user', $userIdentifier)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('rt.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $sessions;
    }

    /**
     * Closes one session belonging to this user.
     *
     * Scoped to the owner deliberately: a session id is a small integer, and an
     * endpoint that revoked by id alone would let any signed-in user sign out
     * anybody else by counting upwards.
     */
    public function revoke(string $userIdentifier, int $sessionId): bool
    {
        $session = $this->entityManager->find(RefreshToken::class, $sessionId);

        if (!$session instanceof RefreshToken || $session->getUserIdentifier() !== $userIdentifier) {
            return false;
        }

        if (null !== $session->getRevokedAt()) {
            return false;
        }

        $session->revoke(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    /** @return array<string, mixed> */
    public function describe(RefreshToken $session, ?string $currentTokenHash = null): array
    {
        return [
            'id' => $session->getId(),
            'createdAt' => $session->getCreatedAt()->format(\DATE_ATOM),
            'lastUsedAt' => $session->getLastUsedAt()?->format(\DATE_ATOM),
            'expiresAt' => $session->getExpiresAt()->format(\DATE_ATOM),
            'userAgent' => $session->getUserAgent(),
            'ipAddress' => $session->getIpAddress(),
            // Marking the current session is what stops somebody revoking the
            // one they are reading the list from and wondering why they were
            // signed out.
            'current' => null !== $currentTokenHash && hash_equals($session->getTokenHash(), $currentTokenHash),
        ];
    }
}
