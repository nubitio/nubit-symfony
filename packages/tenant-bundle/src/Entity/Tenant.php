<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Generic control-plane tenant row for column-mode SaaS apps.
 * Applications may replace this entity via {@code nubit_tenant.tenant_entity}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_tenant')]
#[ORM\UniqueConstraint(name: 'UNIQ_NUBIT_TENANT_SLUG', columns: ['slug'])]
class Tenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(length: 80)]
    private string $slug = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $primaryDomain = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $plan = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $status = null;

    /** column | database — drives connection strategy in nubit-tenant. */
    #[ORM\Column(length: 20, options: ['default' => 'column'])]
    private string $isolationMode = 'column';

    /** Full DSN for database-per-tenant mode (postgresql://…). */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $databaseUrl = null;

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
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getPrimaryDomain(): ?string
    {
        return $this->primaryDomain;
    }

    public function setPrimaryDomain(?string $primaryDomain): static
    {
        $this->primaryDomain = $primaryDomain;

        return $this;
    }

    public function getPlan(): ?string
    {
        return $this->plan;
    }

    public function setPlan(?string $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getIsolationMode(): string
    {
        return $this->isolationMode;
    }

    public function setIsolationMode(string $isolationMode): static
    {
        $this->isolationMode = $isolationMode;

        return $this;
    }

    public function getDatabaseUrl(): ?string
    {
        return $this->databaseUrl;
    }

    public function setDatabaseUrl(?string $databaseUrl): static
    {
        $this->databaseUrl = $databaseUrl;

        return $this;
    }
}