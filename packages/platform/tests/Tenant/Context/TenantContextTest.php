<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Tenant\Context;

use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Context\TenantContext as LegacyTenantContext;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    public function testSetAndGetTenant(): void
    {
        $ctx = new TenantContext();
        $ctx->setTenant(1, 'acme', 'acme.example.com', 'req-123');

        $this->assertSame(1, $ctx->getTenantId());
        $this->assertSame('acme', $ctx->getTenantName());
        $this->assertSame('acme.example.com', $ctx->getTenantDomain());
        $this->assertSame('req-123', $ctx->getRequestId());
    }

    public function testClearResetsAllFields(): void
    {
        $ctx = new TenantContext();
        $ctx->setTenant(1, 'acme', 'acme.example.com', 'req-123');
        $ctx->setActor('cli:app:test', 'cli', 'app:test');
        $ctx->clear();

        $this->assertNull($ctx->getTenantId());
        $this->assertNull($ctx->getTenantName());
        $this->assertNull($ctx->getTenantDomain());
        $this->assertNull($ctx->getRequestId());
        $this->assertNull($ctx->getActorIdentifier());
        $this->assertNull($ctx->getChannel());
        $this->assertNull($ctx->getCommandName());
    }

    public function testDefaultsAreNull(): void
    {
        $ctx = new TenantContext();

        $this->assertNull($ctx->getTenantId());
        $this->assertNull($ctx->getTenantName());
        $this->assertNull($ctx->getTenantDomain());
        $this->assertNull($ctx->getRequestId());
        $this->assertNull($ctx->getActorIdentifier());
        $this->assertNull($ctx->getChannel());
        $this->assertNull($ctx->getCommandName());
    }

    public function testSetAndGetActor(): void
    {
        $ctx = new TenantContext();
        $ctx->setActor('cli:app:test', 'cli', 'app:test');

        $this->assertSame('cli:app:test', $ctx->getActorIdentifier());
        $this->assertSame('cli', $ctx->getChannel());
        $this->assertSame('app:test', $ctx->getCommandName());
    }

    public function testSetActorWithNullCommandName(): void
    {
        $ctx = new TenantContext();
        $ctx->setActor(null, 'http');

        $this->assertNull($ctx->getActorIdentifier());
        $this->assertSame('http', $ctx->getChannel());
        $this->assertNull($ctx->getCommandName());
    }

    public function testSetActorOverwritesPrevious(): void
    {
        $ctx = new TenantContext();
        $ctx->setActor('cli:first', 'cli', 'first');
        $ctx->setActor('scheduler:second', 'scheduler', 'second');

        $this->assertSame('scheduler:second', $ctx->getActorIdentifier());
        $this->assertSame('scheduler', $ctx->getChannel());
        $this->assertSame('second', $ctx->getCommandName());
    }

    public function testPlatformContextTracksTenantAndActorLifecycle(): void
    {
        $context = new TenantContext();

        $context->setTenant(42, 'acme', 'acme.example.test', 'req-42');
        $context->setActor('user:99', 'http', 'invoice:create');

        self::assertSame(42, $context->getTenantId());
        self::assertSame('acme', $context->getTenantName());
        self::assertSame('acme.example.test', $context->getTenantDomain());
        self::assertSame('req-42', $context->getRequestId());
        self::assertSame('user:99', $context->getActorIdentifier());
        self::assertSame('http', $context->getChannel());
        self::assertSame('invoice:create', $context->getCommandName());

        $context->clear();

        self::assertNull($context->getTenantId());
        self::assertNull($context->getTenantName());
        self::assertNull($context->getTenantDomain());
        self::assertNull($context->getRequestId());
        self::assertNull($context->getActorIdentifier());
        self::assertNull($context->getChannel());
        self::assertNull($context->getCommandName());
    }
    public function testLegacyCoreContextRemainsCompatibleWithPlatformContext(): void
    {
        $context = new LegacyTenantContext();

        self::assertInstanceOf(TenantContext::class, $context);

        $context->setTenant(7, 'legacy', 'legacy.example.test', 'req-legacy');
        $context->setActor('cli:tenant:sync', 'cli', 'tenant:sync');

        self::assertSame(7, $context->getTenantId());
        self::assertSame('legacy', $context->getTenantName());
        self::assertSame('legacy.example.test', $context->getTenantDomain());
        self::assertSame('req-legacy', $context->getRequestId());
        self::assertSame('cli:tenant:sync', $context->getActorIdentifier());
        self::assertSame('cli', $context->getChannel());
        self::assertSame('tenant:sync', $context->getCommandName());
    }
}
