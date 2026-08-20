<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Analytics\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nubit\Platform\Analytics\AnalyticsPurpose;
use Nubit\Platform\Analytics\SanitizedAnalyticsEvent;
use Throwable;

#[ORM\Entity]
#[ORM\Table(name: 'nubit_analytics_outbox')]
#[ORM\UniqueConstraint(name: 'UNIQ_NUBIT_ANALYTICS_EVENT', columns: ['event_id'])]
#[ORM\Index(name: 'IDX_NUBIT_ANALYTICS_DUE', columns: ['delivered_at', 'next_attempt_at', 'id'])]
final class AnalyticsOutboxEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'event_id', length: 128)]
    private string $eventId;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    #[ORM\Column(length: 20, enumType: AnalyticsPurpose::class)]
    private AnalyticsPurpose $purpose;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $properties;

    #[ORM\Column(name: 'tenant_id', nullable: true)]
    private ?int $tenantId;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'next_attempt_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $nextAttemptAt;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error_type', length: 255, nullable: true)]
    private ?string $lastErrorType = null;

    #[ORM\Column(name: 'delivered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deliveredAt = null;

    public function __construct(SanitizedAnalyticsEvent $event, ?DateTimeImmutable $createdAt = null)
    {
        $this->eventId = $event->id;
        $this->name = $event->name;
        $this->schemaVersion = $event->schemaVersion;
        $this->purpose = $event->purpose;
        $this->properties = $event->properties;
        $this->tenantId = $event->tenantId;
        $this->occurredAt = $event->occurredAt;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
        $this->nextAttemptAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function isDelivered(): bool
    {
        return null !== $this->deliveredAt;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return !$this->isDelivered() && $this->nextAttemptAt <= $now;
    }

    public function toEvent(): SanitizedAnalyticsEvent
    {
        return new SanitizedAnalyticsEvent(
            $this->eventId,
            $this->name,
            $this->schemaVersion,
            $this->purpose,
            $this->properties,
            $this->occurredAt,
            $this->tenantId,
        );
    }

    public function markDelivered(DateTimeImmutable $now): void
    {
        $this->deliveredAt = $now;
        $this->lastErrorType = null;
    }

    public function markFailed(Throwable $exception, DateTimeImmutable $now, int $maximumDelaySeconds = 3600): void
    {
        ++$this->attempts;
        $delay = min($maximumDelaySeconds, 2 ** min($this->attempts, 20));
        $this->nextAttemptAt = $now->modify(sprintf('+%d seconds', $delay));
        $this->lastErrorType = $exception::class;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastErrorType(): ?string
    {
        return $this->lastErrorType;
    }

    public function getNextAttemptAt(): DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }
}
