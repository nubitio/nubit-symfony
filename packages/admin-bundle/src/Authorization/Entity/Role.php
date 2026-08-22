<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nubit\ApiPlatform\Attribute\Authorized;
use Nubit\Platform\Money\Currency;
use Nubit\Platform\Money\Money;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Entity\TenantOwnedTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named set of permissions, and the amounts they are capped at.
 *
 * Roles are data, not code. "Warehouse supervisor may approve up to €5,000" is
 * a decision the business changes without a deploy, and an ERP that requires
 * one is an ERP whose permissions stop matching how the company actually works
 * within a quarter.
 *
 * The Symfony role name (`ROLE_WAREHOUSE_SUPERVISOR`) stays the identity, so an
 * application already using `ROLE_*` keeps working and adopts permissions where
 * it needs the granularity — not in one migration.
 *
 * This is an ApiResource so the administration screen is the CRUD engine
 * itself, generated from the same contract as everything else.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_role')]
#[ORM\UniqueConstraint(name: 'UNIQ_NUBIT_ROLE_NAME', columns: ['name'])]
#[ApiResource(
    operations: [new GetCollection(), new Get(), new Post(), new Patch(), new Delete()],
    // Administering roles is administering authorization itself; nothing below
    // ROLE_ADMIN has any business here, whatever the permission catalogue says.
    security: "is_granted('ROLE_ADMIN')",
)]
#[Authorized(resource: 'role')]
class Role implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Symfony role name, e.g. ROLE_WAREHOUSE_SUPERVISOR. */
    #[ORM\Column(length: 80)]
    #[Assert\Regex('/^ROLE_[A-Z0-9_]+$/', message: 'A role name looks like ROLE_SOMETHING.')]
    private string $name = '';

    #[ORM\Column(length: 160)]
    private string $label = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    /**
     * Permission name => `{amount, currency, scale}`.
     *
     * Stored in the money wire shape rather than as a float, for the same reason
     * everything else is: a limit that drifts by a cent is a limit that
     * sometimes approves what it should refuse.
     *
     * @var array<string, array{amount: string, currency: string, scale?: int}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $limits = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = strtoupper(trim($name));

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** @return list<string> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /** @param list<string> $permissions */
    public function setPermissions(array $permissions): static
    {
        $this->permissions = array_values(array_unique(array_map(strtolower(...), $permissions)));

        return $this;
    }

    public function grants(string $permission): bool
    {
        return in_array(strtolower($permission), $this->permissions, true);
    }

    /** @return array<string, array{amount: string, currency: string, scale?: int}> */
    public function getLimits(): array
    {
        return $this->limits;
    }

    /** @param array<string, array{amount: string, currency: string, scale?: int}> $limits */
    public function setLimits(array $limits): static
    {
        $this->limits = $limits;

        return $this;
    }

    /**
     * The cap this role puts on a permission, if any.
     *
     * A malformed stored limit is treated as *no* limit rather than as zero:
     * zero would silently refuse everything, and a refusal nobody can explain is
     * worse than an unbounded permission somebody can see in the role screen.
     */
    public function limitFor(string $permission): ?Money
    {
        $limit = $this->limits[strtolower($permission)] ?? null;

        if (null === $limit || !isset($limit['amount'], $limit['currency'])) {
            return null;
        }

        try {
            return Money::of($limit['amount'], Currency::of($limit['currency'], $limit['scale'] ?? null));
        } catch (\Throwable) {
            return null;
        }
    }
}
