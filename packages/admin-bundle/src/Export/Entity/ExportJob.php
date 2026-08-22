<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Entity\TenantOwnedTrait;

/**
 * A spreadsheet somebody asked for that is too big to hand over in a response.
 *
 * The inline export is nicer whenever it can finish: no waiting, no
 * notification, no link. It stops being nicer at the row count where the
 * request times out or the process runs out of memory — and the row count where
 * that happens is exactly the export somebody actually needed.
 *
 * The row filters are stored rather than the rows. A job that captured a result
 * set would be a second copy of the data with its own staleness; re-running the
 * query in the worker is both smaller and more correct.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_export_job')]
#[ORM\Index(name: 'IDX_NUBIT_EXPORT_JOB_OWNER', columns: ['requested_by', 'status'])]
class ExportJob implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_READY = 'ready';
    public const string STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: \Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator::class)]
    #[ORM\Column]
    private ?string $id = null;

    #[ORM\Column(name: 'resource_class', length: 255)]
    private string $resourceClass;

    /** The grid query as it was when the export was asked for. */
    #[ORM\Column(type: Types::JSON)]
    private array $filters = [];

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(name: 'storage_path', length: 512, nullable: true)]
    private ?string $storagePath = null;

    #[ORM\Column(name: 'row_count', nullable: true)]
    private ?int $rowCount = null;

    #[ORM\Column(name: 'byte_size', nullable: true)]
    private ?int $byteSize = null;

    #[ORM\Column(name: 'failure_reason', type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(name: 'requested_by', length: 180, nullable: true)]
    private ?string $requestedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * @param class-string         $resourceClass
     * @param array<string, mixed> $filters
     */
    public function __construct(string $resourceClass, array $filters, string $filename, ?string $requestedBy)
    {
        $this->resourceClass = $resourceClass;
        $this->filters = $filters;
        $this->filename = $filename;
        $this->requestedBy = $requestedBy;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    /** @return class-string */
    public function getResourceClass(): string
    {
        /** @var class-string $class */
        $class = $this->resourceClass;

        return $class;
    }

    /** @return array<string, mixed> */
    public function getFilters(): array
    {
        $filters = [];
        /** @var mixed $value */
        foreach ($this->filters as $key => $value) {
            $filters[(string) $key] = $value;
        }

        return $filters;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isReady(): bool
    {
        return self::STATUS_READY === $this->status;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    public function getByteSize(): ?int
    {
        return $this->byteSize;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getRequestedBy(): ?string
    {
        return $this->requestedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markRunning(): static
    {
        $this->status = self::STATUS_RUNNING;

        return $this;
    }

    public function markReady(string $storagePath, int $rowCount, int $byteSize): static
    {
        $this->status = self::STATUS_READY;
        $this->storagePath = $storagePath;
        $this->rowCount = $rowCount;
        $this->byteSize = $byteSize;
        $this->failureReason = null;
        $this->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    public function markFailed(string $reason): static
    {
        $this->status = self::STATUS_FAILED;
        // Truncated: a stack trace belongs in the log, not in a column every
        // listing selects.
        $this->failureReason = mb_substr($reason, 0, 1000);
        $this->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }
}
