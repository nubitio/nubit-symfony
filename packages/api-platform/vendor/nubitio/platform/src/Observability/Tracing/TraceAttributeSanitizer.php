<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Tracing;

use Nubit\Platform\Privacy\DataPurpose;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Privacy\DataSink;

final readonly class TraceAttributeSanitizer
{
    public function __construct(
        private DataRedactor $redactor,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    // The redactor returns mixed by contract; this method narrows it to valid OTel attribute values.
    // @mago-expect analysis:mixed-assignment
    public function sanitize(array $attributes): array
    {
        $safeAttributes = self::arrayValue($this->redactor->redact(
            $attributes,
            DataSink::Trace,
            DataPurpose::Operational,
        ));
        if ([] === $safeAttributes) {
            return [];
        }

        $result = [];
        foreach ($safeAttributes as $key => $value) {
            if (!is_string($key) || '' === $key) {
                continue;
            }

            if (null === $value || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $result[$key] = $value;
                continue;
            }

            $list = self::scalarList($value);
            if (null !== $list) {
                $result[$key] = $list;
            }
        }

        return $result;
    }

    /** @return array<array-key, mixed> */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<bool|int|float|string>|null */
    // @mago-expect analysis:mixed-assignment
    private static function scalarList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $type = null;
        $result = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                return null;
            }
            $itemType = get_debug_type($item);
            if (null !== $type && $itemType !== $type) {
                return null;
            }
            $type = $itemType;
            $result[] = $item;
        }

        return $result;
    }
}
