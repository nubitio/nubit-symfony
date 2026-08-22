<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Document;

/**
 * Everything a template needs to know that is not the resource itself.
 *
 * Passed rather than looked up so a template renders the same way from an HTTP
 * request, a queue worker and a test. A document that reads the ambient locale
 * or the current time produces a different file depending on who triggered it,
 * which is precisely what an archived record must not do.
 */
final readonly class DocumentRenderContext
{
    /** @param array<string, mixed> $options Extra values passed by the caller at issue time. */
    public function __construct(
        public ?string $documentNumber,
        public \DateTimeImmutable $issuedAt,
        public string $locale = 'en',
        public string $timeZone = 'UTC',
        public ?string $issuedBy = null,
        public ?int $tenantId = null,
        public array $options = [],
    ) {}

    /** The issue instant as the reader should see it, not as it is stored. */
    public function issuedAtLocal(): \DateTimeImmutable
    {
        return $this->issuedAt->setTimezone(new \DateTimeZone($this->timeZone));
    }
}
