<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Entity\RefreshToken;

final readonly class DoctrineRefreshTokenStore implements RefreshTokenStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(
        string $jti,
        string $tokenHash,
        string $userIdentifier,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->entityManager->persist(new RefreshToken($jti, $tokenHash, $userIdentifier, $expiresAt));
        $this->entityManager->flush();
    }

    public function isActiveByHash(string $tokenHash): bool
    {
        return null !== $this->findActiveByHash($tokenHash);
    }

    public function revokeByHash(string $tokenHash): void
    {
        $token = $this->findActiveByHash($tokenHash);
        if (null !== $token) {
            $token->revoke(new DateTimeImmutable());
            $this->entityManager->flush();
        }
    }

    public function revokeAllForUser(string $userIdentifier): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->update(RefreshToken::class, 'rt')
            ->set('rt.revokedAt', ':revokedAt')
            ->where('rt.userIdentifier = :userIdentifier')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('revokedAt', new DateTimeImmutable())
            ->setParameter('userIdentifier', $userIdentifier)
            ->getQuery()
            ->execute();
    }

    public function purgeExpired(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->delete(RefreshToken::class, 'rt')
            ->where('rt.expiresAt < :now')
            ->orWhere('rt.revokedAt IS NOT NULL AND rt.revokedAt < :cleanupDate')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('cleanupDate', new DateTimeImmutable('-30 days'))
            ->getQuery()
            ->execute();
    }

    private function findActiveByHash(string $tokenHash): ?RefreshToken
    {
        /** @var RefreshToken|null $token */
        $token = $this->entityManager->createQueryBuilder()
            ->select('rt')
            ->from(RefreshToken::class, 'rt')
            ->where('rt.tokenHash = :hash')
            ->andWhere('rt.revokedAt IS NULL')
            ->andWhere('rt.expiresAt > :now')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();

        return $token;
    }
}
