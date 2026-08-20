<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy\Policy;

use Nubit\Platform\Privacy\DataClassification;
use Nubit\Platform\Privacy\DataPurpose;
use Nubit\Platform\Privacy\DataSink;
use Nubit\Platform\Privacy\RedactionStrategy;
use Nubit\Platform\Privacy\SensitiveDataMetadata;

final readonly class DefaultSensitiveDataPolicy implements SensitiveDataPolicyInterface
{
    public function strategy(SensitiveDataMetadata $metadata, DataSink $sink, DataPurpose $purpose): RedactionStrategy
    {
        if (!$metadata->permits($purpose)) {
            return RedactionStrategy::Drop;
        }

        if (null !== $metadata->strategy) {
            if (
                DataClassification::Restricted === $metadata->classification
                && !in_array(
                    $metadata->strategy,
                    [RedactionStrategy::Drop, RedactionStrategy::Redact, RedactionStrategy::Tokenize],
                    true,
                )
            ) {
                return RedactionStrategy::Drop;
            }

            return $metadata->strategy;
        }

        return match ($metadata->classification) {
            DataClassification::Public => RedactionStrategy::Allow,
            DataClassification::Internal => DataSink::Metric === $sink
                ? RedactionStrategy::Drop
                : RedactionStrategy::Allow,
            DataClassification::Confidential => match ($sink) {
                DataSink::Log, DataSink::Audit, DataSink::Export => RedactionStrategy::Mask,
                DataSink::Trace, DataSink::Analytics => RedactionStrategy::Hash,
                DataSink::Metric, DataSink::Webhook => RedactionStrategy::Drop,
            },
            DataClassification::Restricted => RedactionStrategy::Drop,
        };
    }
}
