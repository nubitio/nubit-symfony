<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Privacy;

use Nubit\Platform\Privacy\DataClassification;
use Nubit\Platform\Privacy\DataPurpose;
use Nubit\Platform\Privacy\DataSink;
use Nubit\Platform\Privacy\Policy\DefaultSensitiveDataPolicy;
use Nubit\Platform\Privacy\RedactionStrategy;
use Nubit\Platform\Privacy\SensitiveDataMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaultSensitiveDataPolicyTest extends TestCase
{
    #[DataProvider('policyCases')]
    public function testDefaultPolicy(
        DataClassification $classification,
        DataSink $sink,
        RedactionStrategy $expected,
    ): void {
        $actual = (new DefaultSensitiveDataPolicy())->strategy(
            new SensitiveDataMetadata($classification),
            $sink,
            DataPurpose::Operational,
        );

        self::assertSame($expected, $actual);
    }

    /** @return iterable<string, array{DataClassification, DataSink, RedactionStrategy}> */
    public static function policyCases(): iterable
    {
        yield 'public log' => [DataClassification::Public, DataSink::Log, RedactionStrategy::Allow];
        yield 'internal metric' => [DataClassification::Internal, DataSink::Metric, RedactionStrategy::Drop];
        yield 'confidential log' => [DataClassification::Confidential, DataSink::Log, RedactionStrategy::Mask];
        yield 'confidential trace' => [DataClassification::Confidential, DataSink::Trace, RedactionStrategy::Hash];
        yield 'confidential webhook' => [DataClassification::Confidential, DataSink::Webhook, RedactionStrategy::Drop];
        yield 'restricted audit' => [DataClassification::Restricted, DataSink::Audit, RedactionStrategy::Drop];
    }

    public function testRestrictedExplicitAllowStillFailsClosed(): void
    {
        $actual = (new DefaultSensitiveDataPolicy())->strategy(
            new SensitiveDataMetadata(DataClassification::Restricted, RedactionStrategy::Allow),
            DataSink::Log,
            DataPurpose::Operational,
        );

        self::assertSame(RedactionStrategy::Drop, $actual);
    }
}
