<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document;

use Nubit\AdminBundle\Document\Entity\IssuedDocument;

/**
 * The JSON shape the frontend reads for an issued document.
 *
 * Written by hand rather than serialized from the entity: the entity carries a
 * storage path, and a storage path in a response tells a client where the
 * archive keeps its files — information it has no use for and an attacker does.
 */
final class DocumentPayload
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(IssuedDocument $document): array
    {
        return [
            'id' => $document->getId(),
            'number' => $document->getDocumentNumber(),
            'status' => $document->getStatus(),
            'mediaType' => $document->getMediaType(),
            'byteSize' => $document->getByteSize(),
            'checksum' => $document->getChecksum(),
            'issuedAt' => $document->getIssuedAt()->format(\DATE_ATOM),
            'issuedBy' => $document->getIssuedBy(),
            'supersedes' => $document->getSupersedesId(),
            'supersededBy' => $document->getSupersededById(),
            'failureReason' => $document->getFailureReason(),
            'downloadUrl' => sprintf('/api/documents/%s/file', (string) $document->getId()),
        ];
    }

    /**
     * @param list<IssuedDocument> $documents
     *
     * @return list<array<string, mixed>>
     */
    public static function all(array $documents): array
    {
        return array_map(self::of(...), $documents);
    }
}
