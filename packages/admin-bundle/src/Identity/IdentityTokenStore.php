<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Identity\Entity\IdentityToken;

/**
 * Issues and spends single-use tokens.
 *
 * The plaintext token exists only in the return value of {@see issue()}; the
 * row keeps a SHA-256 of it. Everything downstream — the email, the link, the
 * acceptance form — works from that plaintext, and the database is useless to
 * whoever reads it.
 */
final readonly class IdentityTokenStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param list<string> $roles
     *
     * @return array{token: string, record: IdentityToken} The plaintext, once.
     */
    public function issue(
        string $purpose,
        string $subject,
        \DateInterval $lifetime,
        array $roles = [],
        ?string $createdBy = null,
    ): array {
        // Any token still outstanding for the same subject and purpose is
        // revoked. Asking for a second reset link must invalidate the first,
        // otherwise every request widens the window an attacker has.
        $this->revokeOutstanding($purpose, $subject);

        // 256 bits: a token that is guessed is an account that is taken.
        $plain = bin2hex(random_bytes(32));

        $record = new IdentityToken(
            $purpose,
            $subject,
            self::hash($plain),
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->add($lifetime),
        );
        $record->setRoles($roles)->setCreatedBy($createdBy);

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return ['token' => $plain, 'record' => $record];
    }

    /** The usable token behind this plaintext, or null. Never says which of the two it was. */
    public function findUsable(string $purpose, string $plain): ?IdentityToken
    {
        $record = $this->entityManager
            ->getRepository(IdentityToken::class)
            ->findOneBy(['purpose' => $purpose, 'tokenHash' => self::hash($plain)]);

        if (!$record instanceof IdentityToken || !$record->isUsable()) {
            return null;
        }

        return $record;
    }

    public function consume(IdentityToken $record): void
    {
        $record->consume();
        $this->entityManager->flush();
    }

    public function revokeOutstanding(string $purpose, string $subject): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return (int) $this->entityManager
            ->createQueryBuilder()
            ->update(IdentityToken::class, 't')
            ->set('t.revokedAt', ':now')
            ->where('t.purpose = :purpose')
            ->andWhere('t.subject = :subject')
            ->andWhere('t.consumedAt IS NULL')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('purpose', $purpose)
            ->setParameter('subject', $subject)
            ->getQuery()
            ->execute();
    }

    /** @return list<IdentityToken> */
    public function outstanding(string $purpose): array
    {
        /** @var list<IdentityToken> $records */
        $records = $this->entityManager
            ->createQueryBuilder()
            ->select('t')
            ->from(IdentityToken::class, 't')
            ->where('t.purpose = :purpose')
            ->andWhere('t.consumedAt IS NULL')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('purpose', $purpose)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $records;
    }

    public function purgeExpired(\DateInterval $keepFor): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(IdentityToken::class, 't')
            ->where('t.expiresAt < :cutoff')
            ->setParameter('cutoff', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->sub($keepFor))
            ->getQuery()
            ->execute();
    }

    /**
     * SHA-256, not a password hash.
     *
     * The token is 256 bits of randomness with no dictionary behind it, so a
     * work factor buys nothing — and a lookup by hash has to be a single
     * indexed query rather than a scan comparing every row.
     */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
