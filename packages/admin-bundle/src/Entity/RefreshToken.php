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

    /**
     * What the session was opened from.
     *
     * Nullable and purely descriptive: it exists so the person reviewing their
     * own active sessions can tell "my laptop" from "something in another
     * country", which is the only way a session list is actionable at all.
     */
    #[ORM\Column(name: 'user_agent', length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** Updated on rotation, which is the only moment the session proves it is still alive. */
    #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    public function __construct(string $jti, string $tokenHash, string $userIdentifier, DateTimeImmutable $expiresAt)
    {
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

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function describeClient(?string $userAgent, ?string $ipAddress): static
    {
        // Truncated to the column width rather than rejected: a user agent is
        // client-supplied and arbitrarily long, and losing a session record
        // because a browser sent a novel is not a trade worth making.
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->ipAddress = null === $ipAddress ? null : mb_substr($ipAddress, 0, 45);

        return $this;
    }

    public function touch(DateTimeImmutable $when): static
    {
        $this->lastUsedAt = $when;

        return $this;
    }
}
