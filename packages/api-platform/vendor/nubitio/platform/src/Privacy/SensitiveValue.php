<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy;

/** Explicit classification for values placed in otherwise untyped arrays. */
final readonly class SensitiveValue
{
    /** @param list<DataPurpose> $purposes */
    public function __construct(
        public mixed $value,
        public DataClassification $classification,
        public ?RedactionStrategy $strategy = null,
        public array $purposes = [],
    ) {}

    public function metadata(): SensitiveDataMetadata
    {
        return new SensitiveDataMetadata($this->classification, $this->strategy, $this->purposes);
    }
}
