<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Audit\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One audited write: a create/update/delete of an #[Auditable] entity with
 * its field-level before/after diff. Not an ApiResource — rows are served by
 * the audit-trail route in the exact shape @nubitio/crud's AuditTrailPanel
 * renders.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_audit_log')]
#[ORM\Index(name: 'IDX_NUBIT_AUDIT_RESOURCE', columns: ['resource', 'resource_id', 'created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(length: 100)]
    private string $resource;

    #[ORM\Column(name: 'resource_id', length: 64)]
    private string $resourceId;

    /** @var 'create'|'update'|'delete' */
    #[ORM\Column(length: 10)]
    private string $action;

    /** @var array<string, array{before: mixed, after: mixed}> */
    #[ORM\Column(type: Types::JSON)]
    private array $changes;

    #[ORM\Column(length: 180)]
    private string $username;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @param 'create'|'update'|'delete' $action
     * @param array<string, array{before: mixed, after: mixed}> $changes
     */
    public function __construct(
        string $resource,
        string $resourceId,
        string $action,
        array $changes,
        string $username,
    ) {
        $this->resource = $resource;
        $this->resourceId = $resourceId;
        $this->action = $action;
        $this->changes = $changes;
        $this->username = $username;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    /** @return 'create'|'update'|'delete' */
    public function getAction(): string
    {
        return $this->action;
    }

    /** @return array<string, array{before: mixed, after: mixed}> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
