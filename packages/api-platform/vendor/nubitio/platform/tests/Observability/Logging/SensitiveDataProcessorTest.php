<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Observability\Logging;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Nubit\Platform\Observability\Logging\SensitiveDataProcessor;
use Nubit\Platform\Privacy\DataClassification;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Privacy\SensitiveValue;
use PHPUnit\Framework\TestCase;

final class SensitiveDataProcessorTest extends TestCase
{
    public function testRedactsStructuredContextAndExtraButDoesNotRewriteMessage(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Caller is responsible for text-only messages',
            context: [
                'email' => new SensitiveValue('canary@example.test', DataClassification::Confidential),
                'token' => new SensitiveValue('secret-canary', DataClassification::Restricted),
            ],
            extra: ['safe' => true],
        );

        $processed = (new SensitiveDataProcessor(new DataRedactor()))($record);

        self::assertSame('[MASKED]test', $processed->context['email']);
        self::assertArrayNotHasKey('token', $processed->context);
        self::assertSame(['safe' => true], $processed->extra);
        self::assertSame($record->message, $processed->message);
    }
}
