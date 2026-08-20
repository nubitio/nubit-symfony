<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Privacy;

use Nubit\Platform\Privacy\Attribute\SensitiveData;
use Nubit\Platform\Privacy\DataClassification;
use Nubit\Platform\Privacy\DataPurpose;
use Nubit\Platform\Privacy\DataRedactor;
use Nubit\Platform\Privacy\DataSink;
use Nubit\Platform\Privacy\RedactionStrategy;
use Nubit\Platform\Privacy\SensitiveDataMetadata;
use Nubit\Platform\Privacy\SensitiveValue;
use Nubit\Platform\Privacy\Tokenization\SensitiveDataTokenizerInterface;
use PHPUnit\Framework\TestCase;

/** @mago-expect analysis:mixed-assignment(6) */
final class DataRedactorTest extends TestCase
{
    private const string CANARY_EMAIL = 'privacy-canary@example.test';
    private const string CANARY_TOKEN = 'secret-token-canary-7391';

    public function testRedactsAnnotatedObjectWithoutInvokingGetters(): void
    {
        $payload = (new DataRedactor(hmacKey: 'test-hmac-key'))->redact(
            new PrivacyFixture('visible', self::CANARY_EMAIL, self::CANARY_TOKEN),
            DataSink::Log,
        );

        self::assertIsArray($payload);
        self::assertSame('visible', $payload['name']);
        self::assertSame('[MASKED]test', $payload['email']);
        self::assertArrayNotHasKey('token', $payload);
        self::assertFalse(PrivacyFixture::$getterCalled);
        self::assertStringNotContainsString(self::CANARY_EMAIL, json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::CANARY_TOKEN, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testUsesStableHmacForConfidentialAnalyticsValues(): void
    {
        $redactor = new DataRedactor(hmacKey: 'test-hmac-key');
        $value = new SensitiveValue(self::CANARY_EMAIL, DataClassification::Confidential);

        $first = $redactor->redact($value, DataSink::Analytics, DataPurpose::Analytics);
        $second = $redactor->redact($value, DataSink::Analytics, DataPurpose::Analytics);

        self::assertIsString($first);
        self::assertSame($first, $second);
        self::assertStringStartsWith('hmac-sha256:', $first);
        self::assertStringNotContainsString(self::CANARY_EMAIL, $first);
    }

    public function testDropsHashWhenNoHmacKeyIsConfigured(): void
    {
        $value = new SensitiveValue(self::CANARY_EMAIL, DataClassification::Confidential);

        self::assertNull((new DataRedactor())->redact($value, DataSink::Analytics, DataPurpose::Analytics));
    }

    public function testPurposeRestrictionFailsClosed(): void
    {
        $value = new SensitiveValue('audit-only', DataClassification::Internal, purposes: [DataPurpose::Audit]);

        self::assertNull((new DataRedactor())->redact($value, DataSink::Log, DataPurpose::Operational));
        self::assertSame('audit-only', (new DataRedactor())->redact($value, DataSink::Audit, DataPurpose::Audit));
    }

    public function testTokenizationRequiresAndUsesExplicitVault(): void
    {
        $value = new SensitiveValue(self::CANARY_TOKEN, DataClassification::Restricted, RedactionStrategy::Tokenize);
        self::assertNull((new DataRedactor())->redact($value, DataSink::Audit, DataPurpose::Audit));

        $tokenizer = new class implements SensitiveDataTokenizerInterface {
            public function tokenize(string $value, SensitiveDataMetadata $metadata): ?string
            {
                return 'vault-token-1';
            }
        };
        $safe = (new DataRedactor(tokenizer: $tokenizer))->redact($value, DataSink::Audit, DataPurpose::Audit);

        self::assertSame('vault-token-1', $safe);
    }

    public function testDetectsCyclesAndDepthLimits(): void
    {
        $fixture = new CircularPrivacyFixture();
        $fixture->child = $fixture;

        $payload = (new DataRedactor(maxDepth: 2))->redact($fixture, DataSink::Log);

        self::assertIsArray($payload);
        self::assertSame('[CIRCULAR]', $payload['child']);
    }

    public function testClassDefaultCanBeOverriddenByProperty(): void
    {
        $payload = (new DataRedactor())->redact(new ClassifiedFixture('public-id', 'private-value'), DataSink::Log);

        self::assertSame(['id' => 'public-id', 'note' => '[MASKED]alue'], $payload);
    }
}

final class PrivacyFixture
{
    public static bool $getterCalled = false;

    public function __construct(
        public string $name,
        #[SensitiveData(DataClassification::Confidential)]
        private string $email,
        #[SensitiveData(DataClassification::Restricted)]
        /** @mago-expect analysis:unused-property */
        private string $token,
    ) {
        self::$getterCalled = false;
    }

    public function getEmail(): string
    {
        self::$getterCalled = true;

        return $this->email;
    }
}

final class CircularPrivacyFixture
{
    public ?self $child = null;
}

#[SensitiveData(DataClassification::Confidential)]
final readonly class ClassifiedFixture
{
    public function __construct(
        #[SensitiveData(DataClassification::Public)]
        public string $id,
        public string $note,
    ) {}
}
