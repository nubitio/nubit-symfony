<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Observability\Logging;

use Nubit\Platform\Observability\Logging\TenantLogProcessor;
use Nubit\Platform\Tenant\Context\TenantContext;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class TenantLogProcessorTest extends TestCase
{
    public function testProcessorEnrichesRecordWithTenantRequestContext(): void
    {
        $context = new TenantContext();
        $context->setTenant(42, 'acme', 'acme.example.test', 'req-42');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2026-04-25 12:00:00'),
            channel: 'test',
            level: Level::Info,
            message: 'message',
            context: ['existing' => 'value'],
        );

        $processed = (new TenantLogProcessor($context))($record);

        self::assertSame('value', $processed->context['existing']);
        self::assertSame(42, $processed->context['tenant_id']);
        self::assertSame('acme', $processed->context['tenant']);
        self::assertSame('acme.example.test', $processed->context['tenant_domain']);
        self::assertSame('req-42', $processed->context['request_id']);
    }

    public function testProcessorDocumentsNullTenantContextInRecord(): void
    {
        $processed = (new TenantLogProcessor(new TenantContext()))(new LogRecord(
            datetime: new \DateTimeImmutable('2026-04-25 12:00:00'),
            channel: 'test',
            level: Level::Warning,
            message: 'message',
        ));

        self::assertArrayHasKey('tenant_id', $processed->context);
        self::assertArrayHasKey('tenant', $processed->context);
        self::assertArrayHasKey('tenant_domain', $processed->context);
        self::assertArrayHasKey('request_id', $processed->context);
        self::assertNull($processed->context['tenant_id']);
        self::assertNull($processed->context['tenant']);
        self::assertNull($processed->context['tenant_domain']);
        self::assertNull($processed->context['request_id']);
    }
}
