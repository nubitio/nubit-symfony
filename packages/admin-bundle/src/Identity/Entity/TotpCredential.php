<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user's second factor.
 *
 * Keyed by the Symfony user identifier rather than by a foreign key, for the
 * same reason the refresh token is: the bundle must not know the shape of the
 * application's User table.
 *
 * `confirmedAt` is what separates "started enrolling" from "enrolled". Without
 * it a user who scans a QR code and closes the tab has locked themselves out of
 * an account they never finished protecting.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_totp_credential')]
#[ORM\UniqueConstraint(name: 'UNIQ_NUBIT_TOTP_USER', columns: ['user_identifier'])]
class TotpCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_identifier', length: 180)]
    private string $userIdentifier;

    #[ORM\Column(length: 64)]
    private string $secret;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    /**
     * The last time step accepted for this credential.
     *
     * A TOTP code stays valid for its whole window, so without this an attacker
     * who observes one — over a shoulder, in a screenshot, through a phishing
     * proxy — can replay it for up to ninety seconds. Recording the step makes
     * every code single-use.
     */
    #[ORM\Column(name: 'last_used_step', nullable: true)]
    private ?int $lastUsedStep = null;

    /**
     * Hashed one-time recovery codes.
     *
     * Hashed because they are passwords in every sense that matters: a leaked
     * database of plaintext recovery codes bypasses the second factor entirely.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'recovery_codes', type: Types::JSON)]
    private array $recoveryCodes = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $userIdentifier, string $secret)
    {
        $this->userIdentifier = $userIdentifier;
        $this->secret = $secret;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function confirm(): static
    {
        $this->confirmedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    public function getLastUsedStep(): ?int
    {
        return $this->lastUsedStep;
    }

    /** True when this step was already spent, which makes replaying a code impossible. */
    public function isStepSpent(int $step): bool
    {
        return null !== $this->lastUsedStep && $step <= $this->lastUsedStep;
    }

    public function markStepUsed(int $step): static
    {
        $this->lastUsedStep = $step;

        return $this;
    }

    /** @return list<string> */
    public function getRecoveryCodes(): array
    {
        return $this->recoveryCodes;
    }

    /** @param list<string> $hashes */
    public function setRecoveryCodes(array $hashes): static
    {
        $this->recoveryCodes = array_values($hashes);

        return $this;
    }

    public function countRecoveryCodes(): int
    {
        return count($this->recoveryCodes);
    }

    /** Removes one hash, so a recovery code cannot be used twice. */
    public function consumeRecoveryCode(string $hash): static
    {
        $this->recoveryCodes = array_values(array_filter(
            $this->recoveryCodes,
            static fn(string $stored): bool => $stored !== $hash,
        ));

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
