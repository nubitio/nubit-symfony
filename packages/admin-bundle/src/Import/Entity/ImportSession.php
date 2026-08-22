<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Entity\TenantOwnedTrait;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

/**
 * One attempt at loading a file, from upload to applied.
 *
 * The session exists so the dry run has somewhere to live. An import that
 * validated and applied in a single call would leave the user choosing between
 * trusting it blindly and not importing at all; keeping the analysis as a
 * durable, reviewable thing is what makes "show me what will happen" possible.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_import_session')]
class ImportSession implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    /** Uploaded, not yet analysed. */
    public const string STATUS_UPLOADED = 'uploaded';
    /** Analysed; the report says what applying would do. Nothing has been written. */
    public const string STATUS_ANALYZED = 'analyzed';
    public const string STATUS_APPLIED = 'applied';
    public const string STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column]
    private ?string $id = null;

    #[ORM\Column(length: 255)]
    private string $resourceClass = '';

    #[ORM\Column(length: 255)]
    private string $filename = '';

    #[ORM\Column(length: 512)]
    private string $storagePath = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_UPLOADED;

    #[ORM\Column(length: 10)]
    private string $numberFormat = 'auto';

    /** @var array<string, int> field => column index */
    #[ORM\Column(type: Types::JSON)]
    private array $mapping = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $report = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $createdBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getResourceClass(): string
    {
        return $this->resourceClass;
    }

    public function setResourceClass(string $resourceClass): static
    {
        $this->resourceClass = $resourceClass;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function setStoragePath(string $storagePath): static
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isApplied(): bool
    {
        return self::STATUS_APPLIED === $this->status;
    }

    public function getNumberFormat(): string
    {
        return $this->numberFormat;
    }

    public function setNumberFormat(string $numberFormat): static
    {
        $this->numberFormat = $numberFormat;

        return $this;
    }

    /** @return array<string, int> */
    public function getMapping(): array
    {
        return $this->mapping;
    }

    /** @param array<string, int> $mapping */
    public function setMapping(array $mapping): static
    {
        $this->mapping = $mapping;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getReport(): array
    {
        return $this->report;
    }

    /** @param array<string, mixed> $report */
    public function setReport(array $report): static
    {
        $this->report = $report;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function markApplied(): static
    {
        $this->status = self::STATUS_APPLIED;
        $this->appliedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

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
}
