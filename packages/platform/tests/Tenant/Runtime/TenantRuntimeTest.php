<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Tenant\Runtime;

use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\ResettableTenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use Nubit\Platform\Tenant\Model\TenantDescriptor;
use Nubit\Platform\Tenant\Runtime\TenantRuntime;
use Nubit\Platform\Tenant\Runtime\TenantRuntimeActor;
use PHPUnit\Framework\TestCase;

final class TenantRuntimeTest extends TestCase
{
    public function testActivateSwitchesConnectionAndSetsTypedTenantContext(): void
    {
        $context = new TenantContext();
        $switcher = new RecordingTenantConnectionSwitcher();
        $runtime = new TenantRuntime($switcher, $context);

        $descriptor = $runtime->activate(
            new TenantDescriptor(7, 'acme', primaryDomain: 'acme.example.test'),
            new TenantRuntimeActor('cli:test', 'cli', 'app:test', 'req-7'),
        );

        self::assertSame('acme', $descriptor->name);
        self::assertSame(['acme'], $switcher->tenants);
        self::assertSame(7, $context->getTenantId());
        self::assertSame('acme', $context->getTenantName());
        self::assertSame('acme.example.test', $context->getTenantDomain());
        self::assertSame('req-7', $context->getRequestId());
        self::assertSame('cli:test', $context->getActorIdentifier());
        self::assertSame('cli', $context->getChannel());
        self::assertSame('app:test', $context->getCommandName());
    }

    public function testRunAcceptsLegacyTenantArrayAndClearsContextAfterCallback(): void
    {
        $context = new TenantContext();
        $switcher = new RecordingTenantConnectionSwitcher();
        $runtime = new TenantRuntime($switcher, $context);

        $captured = $runtime->run([
            'id' => 3,
            'name' => 'legacy',
            'primary_domain' => 'legacy.example.test',
        ], static fn(TenantDescriptor $tenant): array => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'domain' => $tenant->primaryDomain,
        ]);

        self::assertSame(['id' => 3, 'name' => 'legacy', 'domain' => 'legacy.example.test'], $captured);
        self::assertSame(['legacy'], $switcher->tenants);
        self::assertNull($context->getTenantId());
        self::assertNull($context->getTenantName());
        self::assertNull($context->getActorIdentifier());
    }

    public function testRunClearsContextAfterCallbackException(): void
    {
        $context = new TenantContext();
        $runtime = new TenantRuntime(new RecordingTenantConnectionSwitcher(), $context);

        try {
            $runtime->run(
                new TenantDescriptor(9, 'failing'),
                static fn(): never => throw new \RuntimeException('failed'),
            );
            self::fail('Expected runtime exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('failed', $exception->getMessage());
        }

        self::assertNull($context->getTenantId());
        self::assertNull($context->getTenantName());
        self::assertNull($context->getActorIdentifier());
    }

    public function testRunResetsResettableConnectionAfterCallback(): void
    {
        $switcher = new ResettableRecordingTenantConnectionSwitcher();
        (new TenantRuntime($switcher, new TenantContext()))->run(
            new TenantDescriptor(9, 'acme'),
            static fn(): null => null,
        );

        self::assertSame(1, $switcher->resets);
    }
}

/** @internal */
class RecordingTenantConnectionSwitcher implements TenantConnectionSwitcherInterface
{
    /** @var list<string> */
    public array $tenants = [];

    public function switchConnection(string $tenant): void
    {
        $this->tenants[] = $tenant;
    }
}

/** @internal */
final class ResettableRecordingTenantConnectionSwitcher extends RecordingTenantConnectionSwitcher implements
    ResettableTenantConnectionSwitcherInterface
{
    public int $resets = 0;

    public function resetConnection(): void
    {
        ++$this->resets;
    }
}
