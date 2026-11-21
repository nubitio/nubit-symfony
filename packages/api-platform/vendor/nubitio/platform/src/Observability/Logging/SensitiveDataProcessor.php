<?php

declare(strict_types=1);

namespace Nubit\Platform\Observability\Logging;

use Monolog\LogRecord;
use Nubit\Platform\Privacy\DataPurpose;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Privacy\DataSink;

/** Redacts structured Monolog context and extra fields. Log messages remain text-only. */
final readonly class SensitiveDataProcessor
{
    public function __construct(
        private DataRedactor $redactor,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: self::arrayValue($this->redactor->redact(
                $record->context,
                DataSink::Log,
                DataPurpose::Operational,
            )),
            extra: self::arrayValue($this->redactor->redact($record->extra, DataSink::Log, DataPurpose::Operational)),
        );
    }

    /** @return array<array-key, mixed> */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
