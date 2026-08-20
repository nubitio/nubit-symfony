<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy;

use BackedEnum;
use DateTimeInterface;
use Nubit\Platform\Privacy\Metadata\SensitiveDataMetadataReader;
use Nubit\Platform\Privacy\Policy\DefaultSensitiveDataPolicy;
use Nubit\Platform\Privacy\Policy\SensitiveDataPolicyInterface;
use Nubit\Platform\Privacy\Tokenization\SensitiveDataTokenizerInterface;
use SplObjectStorage;
use Stringable;
use UnitEnum;

final readonly class DataRedactor
{
    private object $dropped;

    public function __construct(
        private SensitiveDataMetadataReader $metadataReader = new SensitiveDataMetadataReader(),
        private SensitiveDataPolicyInterface $policy = new DefaultSensitiveDataPolicy(),
        #[\SensitiveParameter]
        private ?string $hmacKey = null,
        private ?SensitiveDataTokenizerInterface $tokenizer = null,
        private int $maxDepth = 8,
        private int $maxItems = 1000,
    ) {
        $this->dropped = new \stdClass();
    }

    // Traversing an intentionally untyped payload requires assigning the normalized mixed result.
    // @mago-expect analysis:mixed-assignment
    public function redact(mixed $value, DataSink $sink, DataPurpose $purpose = DataPurpose::Operational): mixed
    {
        $seen = new SplObjectStorage();
        $remaining = $this->maxItems;
        $result = $this->walk($value, $sink, $purpose, $seen, $remaining, 0, null);

        return $result === $this->dropped ? null : $result;
    }

    // This is the single recursive mixed boundary; every value is narrowed before it leaves the method.
    // @mago-expect analysis:mixed-assignment(3)
    private function walk(
        mixed $value,
        DataSink $sink,
        DataPurpose $purpose,
        SplObjectStorage $seen,
        int &$remaining,
        int $depth,
        ?SensitiveDataMetadata $metadata,
    ): mixed {
        if ($depth > $this->maxDepth || $remaining-- <= 0) {
            return '[TRUNCATED]';
        }

        if ($value instanceof SensitiveValue) {
            return $this->walk($value->value, $sink, $purpose, $seen, $remaining, $depth, $value->metadata());
        }

        if (null !== $metadata) {
            $strategy = $this->policy->strategy($metadata, $sink, $purpose);
            if (RedactionStrategy::Allow !== $strategy) {
                return $this->transform($value, $metadata, $strategy);
            }
        }

        if (null === $value || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_resource($value)) {
            return '[RESOURCE]';
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $safe = $this->walk($item, $sink, $purpose, $seen, $remaining, $depth + 1, null);
                if ($safe !== $this->dropped) {
                    $result[$key] = $safe;
                }
            }

            return $result;
        }

        if (!is_object($value)) {
            return '[UNSUPPORTED]';
        }

        if ($seen->offsetExists($value)) {
            return '[CIRCULAR]';
        }
        $seen->offsetSet($value);

        try {
            $classMetadata = $this->metadataReader->forClass($value);
            $result = [];
            foreach ($classMetadata->properties as $name => $property) {
                if ($property->isStatic() || !$property->isInitialized($value)) {
                    continue;
                }
                $propertyMetadata = $classMetadata->propertyMetadata[$name] ?? $classMetadata->classDefault;
                $safe = $this->walk(
                    $property->getValue($value),
                    $sink,
                    $purpose,
                    $seen,
                    $remaining,
                    $depth + 1,
                    $propertyMetadata,
                );
                if ($safe !== $this->dropped) {
                    $result[$name] = $safe;
                }
            }

            return $result;
        } finally {
            $seen->offsetUnset($value);
        }
    }

    private function transform(mixed $value, SensitiveDataMetadata $metadata, RedactionStrategy $strategy): mixed
    {
        return match ($strategy) {
            RedactionStrategy::Drop => $this->dropped,
            RedactionStrategy::Redact => '[REDACTED]',
            RedactionStrategy::Mask => $this->mask($value),
            RedactionStrategy::Hash => $this->hash($value),
            RedactionStrategy::Tokenize => $this->tokenize($value, $metadata),
            RedactionStrategy::Allow => $value,
        };
    }

    private function mask(mixed $value): string
    {
        $string = $this->stringValue($value);
        if (null === $string || '' === $string) {
            return '[REDACTED]';
        }

        $visible = strlen($string) > 4 ? substr($string, -4) : '';

        return '[MASKED]' . $visible;
    }

    private function hash(mixed $value): object|string
    {
        $string = $this->stringValue($value);
        if (null === $string || null === $this->hmacKey || '' === $this->hmacKey) {
            return $this->dropped;
        }

        return 'hmac-sha256:' . hash_hmac('sha256', $string, $this->hmacKey);
    }

    private function tokenize(mixed $value, SensitiveDataMetadata $metadata): object|string
    {
        $string = $this->stringValue($value);
        if (null === $string || null === $this->tokenizer) {
            return $this->dropped;
        }

        return $this->tokenizer->tokenize($string, $metadata) ?? $this->dropped;
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return $value instanceof Stringable ? (string) $value : null;
    }
}
