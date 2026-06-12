<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\RequestBody;
use ArrayObject;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nubit\AdminBundle\Media\Controller\MediaUploadController;
use Nubit\AdminBundle\Media\State\MediaSoftDeleteProcessor;
use Nubit\ApiPlatform\Attribute\SoftDeletable;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

/**
 * A stored upload. Created through `POST /api/media` (multipart/form-data,
 * field `file`) and referenced from other resources by IRI — the pattern
 * @nubitio/react-admin's fileField()/imageField() implement (instant upload
 * on file selection, the parent form submits only the IRI).
 *
 * Serialization is handled by {@see \Nubit\AdminBundle\Media\Serializer\MediaNormalizer},
 * which emits the resolved public URL as `path` regardless of the parent
 * resource's serialization groups.
 */
#[SoftDeletable]
#[ORM\Entity]
#[ORM\Table(name: 'nubit_media')]
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/media/{id}'),
        new Post(
            uriTemplate: '/media',
            controller: MediaUploadController::class,
            openapi: new Operation(
                requestBody: new RequestBody(
                    content: new ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                ],
                            ],
                        ],
                    ]),
                    required: true,
                ),
            ),
            deserialize: false,
        ),
        new Delete(
            uriTemplate: '/media/{id}',
            processor: MediaSoftDeleteProcessor::class,
            output: false,
        ),
    ],
)]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column]
    private ?string $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    /** Storage filename relative to the configured media directory. */
    #[ORM\Column(length: 255)]
    private string $path = '';

    #[ORM\Column(name: 'original_name', length: 255, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(name: 'mime_type', length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }
}
