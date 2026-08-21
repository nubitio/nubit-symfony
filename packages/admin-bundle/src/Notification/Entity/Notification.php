<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Notification\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Entity\TenantOwnedTrait;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One in-app notification. `recipient` is a plain user-identifier string
 * (whatever `UserInterface::getUserIdentifier()` returns in the app) rather
 * than a foreign key to an app-owned User entity — this bundle doesn't know
 * that class, the same reason `Audit\Entity\AuditLog::$username` is a string.
 *
 * Visibility is enforced at the database level: `nubit_notification_recipient`
 * (registered by NotificationModule, enabled per-request by
 * CurrentRecipientFilterListener) restricts every query — GetCollection, Get,
 * and the read-before-write half of Patch — to the authenticated user's own
 * rows. There is deliberately no client-suppliable "recipient" filter: the
 * constraint isn't parameter-driven, so there's nothing to bypass.
 *
 * `mercure: true` pushes new/updated rows to the frontend the same way any
 * other `mercure: true` grid resource does — no manual Hub::publish() call
 * needed (see InAppNotificationChannel).
 */
#[ORM\Entity]
#[ORM\Table(name: 'nubit_notification')]
#[ORM\Index(name: 'IDX_NUBIT_NOTIFICATION_RECIPIENT', columns: ['recipient', 'created_at'])]
#[ApiResource(
    shortName: 'Notification',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('ROLE_USER')", denormalizationContext: ['groups' => ['notification:write']]),
    ],
    normalizationContext: ['groups' => ['notification:read']],
    order: ['createdAt' => 'DESC'],
    mercure: true,
)]
class Notification implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['notification:read'])]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(length: 180)]
    #[Groups(['notification:read'])]
    private string $recipient;

    #[ORM\Column(length: 255)]
    #[Groups(['notification:read'])]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['notification:read'])]
    private string $body;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['notification:read'])]
    private DateTimeImmutable $createdAt;

    public function __construct(string $recipient, string $subject, string $body)
    {
        $this->recipient = $recipient;
        $this->subject = $subject;
        $this->body = $body;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    #[Groups(['notification:read'])]
    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /** Serializer entry point for `PATCH { "read": true }`. */
    #[Groups(['notification:write'])]
    public function setRead(bool $read): void
    {
        $this->readAt = $read ? new DateTimeImmutable() : null;
    }
}
