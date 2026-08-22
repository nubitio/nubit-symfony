<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use, expiring token: a password reset or an invitation.
 *
 * One table for both because the security properties are identical — hashed at
 * rest, short-lived, spent once — and duplicating them is duplicating the
 * chances of getting one of them wrong.
 *
 * The token is stored hashed. A reset token in a leaked database is a password
 * reset for every account that has one pending, so the column has to be useless
 * to whoever reads it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_identity_token')]
#[ORM\UniqueConstraint(name: 'UNIQ_NUBIT_IDENTITY_TOKEN_HASH', columns: ['token_hash'])]
#[ORM\Index(name: 'IDX_NUBIT_IDENTITY_TOKEN_SUBJECT', columns: ['purpose', 'subject'])]
class IdentityToken
{
    public const string PURPOSE_PASSWORD_RESET = 'password_reset';
    public const string PURPOSE_INVITATION = 'invitation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $purpose;

    /** The user identifier for a reset; the invited email address for an invitation. */
    #[ORM\Column(length: 180)]
    private string $subject;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'consumed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** Roles an invitation grants on acceptance. Empty for a reset. @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(name: 'created_by', length: 180, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $purpose, string $subject, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->purpose = $purpose;
        $this->subject = $subject;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /** Usable exactly once, and only before it expires. */
    public function isUsable(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return null === $this->consumedAt && null === $this->revokedAt && $this->expiresAt > $now;
    }

    public function consume(): static
    {
        $this->consumedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    public function revoke(): static
    {
        $this->revokedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = [];
        /** @var mixed $role */
        foreach ($this->roles as $role) {
            if (is_string($role)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values($roles);

        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
