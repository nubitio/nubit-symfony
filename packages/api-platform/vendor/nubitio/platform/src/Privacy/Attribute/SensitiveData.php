<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy\Attribute;

use Attribute;
use InvalidArgumentException;
use Nubit\Platform\Privacy\DataClassification;
use Nubit\Platform\Privacy\DataPurpose;
use Nubit\Platform\Privacy\RedactionStrategy;

#[Attribute(Attribute::TARGET_CLASS
| Attribute::TARGET_PROPERTY
| Attribute::TARGET_PARAMETER
| Attribute::TARGET_METHOD)]
final readonly class SensitiveData
{
    /** @param list<DataPurpose> $purposes Empty means every purpose. */
    public function __construct(
        public DataClassification $classification,
        public ?RedactionStrategy $strategy = null,
        public array $purposes = [],
    ) {
        if (
            DataClassification::Restricted === $classification
            && null !== $strategy
            && !in_array(
                $strategy,
                [RedactionStrategy::Drop, RedactionStrategy::Redact, RedactionStrategy::Tokenize],
                true,
            )
        ) {
            throw new InvalidArgumentException('Restricted data only supports drop, redact or tokenize strategies.');
        }
    }
}
