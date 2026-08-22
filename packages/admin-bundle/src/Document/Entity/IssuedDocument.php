<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Entity\TenantOwnedTrait;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

/**
 * One issued copy of a document, and the bytes that were handed out.
 *
 * The row is append-only by design. Nothing edits an issued document: a
 * correction is a new row pointing back at the one it supersedes, so the
 * archive keeps both the wrong copy that was sent and the right one that
 * replaced it. That is what an auditor asks for, and it is impossible to
 * reconstruct after the fact if the first copy was overwritten.
 *
 * The checksum is stored so a later reader can prove the file on disk is the
 * file that was issued.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_issued_document')]
#[ORM\Index(name: 'IDX_NUBIT_DOC_SUBJECT', columns: ['resource_class', 'resource_id'])]
#[ORM\Index(name: 'IDX_NUBIT_DOC_NUMBER', columns: ['document_number'])]
class IssuedDocument implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    /** Rendering has not happened yet — the row exists so the caller has something to poll. */
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_READY = 'ready';
    public const string STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column]
    private ?string $id = null;

    #[ORM\Column(length: 255)]
    private string $resourceClass = '';

    /** Kept as a string: resources are keyed by int, UUID or a composite rendered as text. */
    #[ORM\Column(length: 64)]
    private string $resourceId = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $documentNumber = null;

    #[ORM\Column(length: 255)]
    private string $template = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $storagePath = null;

    /** SHA-256 of the issued bytes: the evidence that the archived file is the issued file. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksum = null;

    #[ORM\Column(nullable: true)]
    private ?int $byteSize = null;

    #[ORM\Column(length: 100)]
    private string $mediaType = 'application/pdf';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $issuedBy = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $supersedesId = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $supersededById = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    public function __construct()
    {
        $this->issuedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function setResourceId(string $resourceId): static
    {
        $this->resourceId = $resourceId;

        return $this;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(?string $documentNumber): static
    {
        $this->documentNumber = $documentNumber;

        return $this;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function setTemplate(string $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isReady(): bool
    {
        return self::STATUS_READY === $this->status;
    }

    /** Records a successful render. The bytes themselves live in the filesystem. */
    public function markReady(string $storagePath, string $checksum, int $byteSize): static
    {
        $this->status = self::STATUS_READY;
        $this->storagePath = $storagePath;
        $this->checksum = $checksum;
        $this->byteSize = $byteSize;
        $this->failureReason = null;

        return $this;
    }

    public function markFailed(string $reason): static
    {
        $this->status = self::STATUS_FAILED;
        // Truncated: a stack trace belongs in the log, not in a column every
        // history listing selects.
        $this->failureReason = mb_substr($reason, 0, 1000);

        return $this;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function getByteSize(): ?int
    {
        return $this->byteSize;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function setMediaType(string $mediaType): static
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getIssuedBy(): ?string
    {
        return $this->issuedBy;
    }

    public function setIssuedBy(?string $issuedBy): static
    {
        $this->issuedBy = $issuedBy;

        return $this;
    }

    public function getSupersedesId(): ?string
    {
        return $this->supersedesId;
    }

    public function setSupersedesId(?string $supersedesId): static
    {
        $this->supersedesId = $supersedesId;

        return $this;
    }

    public function getSupersededById(): ?string
    {
        return $this->supersededById;
    }

    public function isSuperseded(): bool
    {
        return null !== $this->supersededById;
    }

    public function markSupersededBy(string $documentId): static
    {
        $this->supersededById = $documentId;

        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }
}
