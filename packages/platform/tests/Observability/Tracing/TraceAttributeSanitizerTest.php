<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Observability\Tracing;

use Nubit\Platform\Observability\Tracing\TraceAttributeSanitizer;
use Nubit\Platform\Privacy\DataClassification;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Privacy\SensitiveValue;
use PHPUnit\Framework\TestCase;

final class TraceAttributeSanitizerTest extends TestCase
{
    public function testRedactsSensitiveAttributesAndDropsInvalidOtelValues(): void
    {
        $sanitizer = new TraceAttributeSanitizer(new DataRedactor(hmacKey: 'trace-hmac-key'));

        $safe = $sanitizer->sanitize([
            'safe' => 'value',
            'email' => new SensitiveValue('canary@example.test', DataClassification::Confidential),
            'token' => new SensitiveValue('secret-canary', DataClassification::Restricted),
            'list' => ['one', 'two'],
            'mixed-list' => ['one', 2],
            'nested' => ['not' => 'an otel scalar list'],
        ]);

        self::assertSame('value', $safe['safe']);
        self::assertIsString($safe['email']);
        self::assertStringStartsWith('hmac-sha256:', $safe['email']);
        self::assertArrayNotHasKey('token', $safe);
        self::assertSame(['one', 'two'], $safe['list']);
        self::assertArrayNotHasKey('mixed-list', $safe);
        self::assertArrayNotHasKey('nested', $safe);
        self::assertStringNotContainsString('canary@example.test', json_encode($safe, JSON_THROW_ON_ERROR));
    }
}
