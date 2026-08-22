<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Entity\TenantOwnedTrait;

/**
 * A credential for something that is not a person.
 *
 * Every ERP ends up integrated with something — a webshop, a bank feed, a
 * warehouse scanner — and the alternative to this is a shared human account
 * with a password in a config file, which nobody can rotate, attribute or
 * revoke without breaking a colleague's login.
 *
 * The key is stored as a hash and shown exactly once. The prefix is kept in
 * clear so a key can be identified in a list, in a log line, or in the config
 * file it was pasted into, without being usable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_api_key')]
#[ORM\UniqueConstraint(name: 'UNIQ_NUBIT_API_KEY_HASH', columns: ['key_hash'])]
#[ORM\Index(name: 'IDX_NUBIT_API_KEY_PREFIX', columns: ['prefix'])]
class ApiKey implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    /** Identifies the key without being the key. */
    #[ORM\Column(length: 16)]
    private string $prefix;

    #[ORM\Column(name: 'key_hash', length: 64)]
    private string $keyHash;

    /**
     * The identity the key acts as.
     *
     * A key authenticates as a principal rather than floating free, so
     * permissions, row scope and the audit trail all keep working unchanged —
     * and "who did this" has an answer.
     */
    #[ORM\Column(name: 'user_identifier', length: 180)]
    private string $userIdentifier;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * Written on use, coarsely.
     *
     * Coarsely on purpose: a write on every request turns a read path into a
     * write path. A day's resolution is enough to answer the question this
     * column exists for — "is anything still using this key?" — before someone
     * revokes it.
     */
    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'created_by', length: 180, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $prefix, string $keyHash, string $userIdentifier)
    {
        $this->name = $name;
        $this->prefix = $prefix;
        $this->keyHash = $keyHash;
        $this->userIdentifier = $userIdentifier;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values($roles);

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return null === $this->revokedAt && (null === $this->expiresAt || $this->expiresAt > $now);
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): static
    {
        $this->revokedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /** @return bool whether the stored value actually changed */
    public function touch(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (null !== $this->lastUsedAt && $this->lastUsedAt->format('Y-m-d') === $now->format('Y-m-d')) {
            return false;
        }

        $this->lastUsedAt = $now;

        return true;
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
