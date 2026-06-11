<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Messenger;

use Nubit\Platform\Messenger\ActorStamp;
use Nubit\Platform\Messenger\TenantContextMiddleware;
use Nubit\Platform\Messenger\TenantStamp;
use Nubit\Platform\Messenger\TenantStampMiddleware;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Tenant\Contract\TenantConnectionSwitcherInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class TenantMiddlewareTest extends TestCase
{
    public function testStampMiddlewareAddsTenantAndActorStampsFromContextWhenMissing(): void
    {
        $context = new TenantContext();
        $context->setTenant(42, 'acme', 'acme.example.test', 'req-42');
        $context->setActor('user:99', 'http', 'invoice:create');

        $result = (new TenantStampMiddleware($context))->handle(
            new Envelope(new \stdClass()),
            $this->passthroughStack(),
        );

        $tenantStamp = $result->last(TenantStamp::class);
        $actorStamp = $result->last(ActorStamp::class);

        self::assertNotNull($tenantStamp);
        self::assertSame(42, $tenantStamp->tenantId);
        self::assertSame('acme', $tenantStamp->tenantName);
        self::assertSame('acme.example.test', $tenantStamp->tenantDomain);
        self::assertSame('req-42', $tenantStamp->requestId);

        self::assertNotNull($actorStamp);
        self::assertSame('user:99', $actorStamp->actorIdentifier);
        self::assertSame('http', $actorStamp->channel);
        self::assertSame('invoice:create', $actorStamp->commandName);
    }

    public function testStampMiddlewareDoesNotOverwriteExistingTenantOrActorStamps(): void
    {
        $context = new TenantContext();
        $context->setTenant(42, 'acme', 'acme.example.test', 'req-42');
        $context->setActor('user:99', 'http', 'invoice:create');

        $existingTenantStamp = new TenantStamp(7, 'existing', 'existing.example.test', 'req-existing');
        $existingActorStamp = new ActorStamp('cli:existing', 'cli', 'existing:command');

        $result = (new TenantStampMiddleware($context))->handle(
            new Envelope(new \stdClass(), [$existingTenantStamp, $existingActorStamp]),
            $this->passthroughStack(),
        );

        self::assertSame($existingTenantStamp, $result->last(TenantStamp::class));
        self::assertSame($existingActorStamp, $result->last(ActorStamp::class));
    }

    public function testContextMiddlewareRestoresTenantAndActorForHandlerThenClearsContext(): void
    {
        $context = new TenantContext();
        $switcher = $this->createMock(TenantConnectionSwitcherInterface::class);
        $switcher->expects(self::once())->method('switchConnection')->with('acme');

        $captured = [];
        $stack = $this->capturingStack(function () use ($context, &$captured): void {
            $captured = [
                'tenantId' => $context->getTenantId(),
                'tenantName' => $context->getTenantName(),
                'tenantDomain' => $context->getTenantDomain(),
                'requestId' => $context->getRequestId(),
                'actorIdentifier' => $context->getActorIdentifier(),
                'channel' => $context->getChannel(),
                'commandName' => $context->getCommandName(),
            ];
        });

        (new TenantContextMiddleware($context, $switcher))->handle(
            new Envelope(new \stdClass(), [
                new TenantStamp(42, 'acme', 'acme.example.test', 'req-42'),
                new ActorStamp('user:99', 'http', 'invoice:create'),
            ]),
            $stack,
        );

        self::assertSame([
            'tenantId' => 42,
            'tenantName' => 'acme',
            'tenantDomain' => 'acme.example.test',
            'requestId' => 'req-42',
            'actorIdentifier' => 'user:99',
            'channel' => 'messenger',
            'commandName' => 'invoice:create',
        ], $captured);
        self::assertNull($context->getTenantName());
        self::assertNull($context->getActorIdentifier());
        self::assertNull($context->getChannel());
    }

    public function testContextMiddlewareClearsContextAfterHandlerException(): void
    {
        $context = new TenantContext();
        $switcher = new RecordingTenantConnectionSwitcher();

        try {
            (new TenantContextMiddleware($context, $switcher))->handle(
                new Envelope(new \stdClass(), [new TenantStamp(42, 'acme', null, null)]),
                $this->throwingStack(new \RuntimeException('handler failed')),
            );
            self::fail('Expected handler exception to bubble.');
        } catch (\RuntimeException $exception) {
            self::assertSame('handler failed', $exception->getMessage());
        }

        self::assertNull($context->getTenantId());
        self::assertNull($context->getTenantName());
        self::assertNull($context->getActorIdentifier());
        self::assertNull($context->getChannel());
    }

    private function passthroughStack(): StackInterface
    {
        return $this->stackReturning(new class () implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope;
            }
        });
    }

    private function capturingStack(\Closure $callback): StackInterface
    {
        return $this->stackReturning(new class ($callback) implements MiddlewareInterface {
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->callback)();

                return $envelope;
            }
        });
    }

    private function throwingStack(\Throwable $throwable): StackInterface
    {
        return $this->stackReturning(new class ($throwable) implements MiddlewareInterface {
            public function __construct(private readonly \Throwable $throwable)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw $this->throwable;
            }
        });
    }

    private function stackReturning(MiddlewareInterface $middleware): StackInterface
    {
        return new SingleMiddlewareStack($middleware);
    }
}

/** @internal */
final readonly class SingleMiddlewareStack implements StackInterface
{
    public function __construct(private MiddlewareInterface $middleware)
    {
    }

    public function next(): MiddlewareInterface
    {
        return $this->middleware;
    }
}

/** @internal */
final class RecordingTenantConnectionSwitcher implements TenantConnectionSwitcherInterface
{
    /** @var list<string> */
    public array $tenants = [];

    public function switchConnection(string $tenant): void
    {
        $this->tenants[] = $tenant;
    }
}
