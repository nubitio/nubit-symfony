<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Refresh token record. Stores the user's Symfony identifier (string) instead
 * of a foreign key so the bundle stays agnostic of the application's User
 * entity and table layout.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_refresh_token')]
#[ORM\UniqueConstraint(name: 'UNIQ_NUBIT_REFRESH_TOKEN_JTI', columns: ['jti'])]
#[ORM\UniqueConstraint(name: 'UNIQ_NUBIT_REFRESH_TOKEN_HASH', columns: ['token_hash'])]
#[ORM\Index(name: 'IDX_NUBIT_REFRESH_TOKEN_USER', columns: ['user_identifier'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(length: 64)]
    private string $jti;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'user_identifier', length: 180)]
    private string $userIdentifier;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $jti,
        string $tokenHash,
        string $userIdentifier,
        DateTimeImmutable $expiresAt,
    ) {
        $this->jti = $jti;
        $this->tokenHash = $tokenHash;
        $this->userIdentifier = $userIdentifier;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJti(): string
    {
        return $this->jti;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function revoke(DateTimeImmutable $when): void
    {
        $this->revokedAt = $when;
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $this->expiresAt > $now;
    }
}
