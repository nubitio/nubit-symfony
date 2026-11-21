<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy;

final readonly class SensitiveDataMetadata
{
    /** @param list<DataPurpose> $purposes */
    public function __construct(
        public DataClassification $classification,
        public ?RedactionStrategy $strategy = null,
        public array $purposes = [],
    ) {}

    public function permits(DataPurpose $purpose): bool
    {
        return [] === $this->purposes || in_array($purpose, $this->purposes, true);
    }
}
