<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Mercure;

use Nubit\AdminBundle\Mercure\FailSafeHub;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class FailSafeHubTest extends TestCase
{
    /** @var list<array{level: mixed, message: string}> */
    private array $logs = [];

    public function testPublishDelegatesToTheInnerHub(): void
    {
        $hub = $this->makeHub($this->workingInnerHub(), withRequest: true);

        self::assertSame('event-id', $hub->publish(new Update('topic-a', 'data')));
    }

    public function testHttpRequestFailuresAreLoggedAndSwallowed(): void
    {
        $hub = $this->makeHub($this->brokenInnerHub(), withRequest: true);

        $result = $hub->publish(new Update('topic-a', 'data'));

        self::assertSame('', $result);
        self::assertCount(1, $this->logs);
        self::assertSame('warning', $this->logs[0]['level']);
    }

    public function testWorkerAndConsoleFailuresAreRethrownSoMessengerRetries(): void
    {
        $hub = $this->makeHub($this->brokenInnerHub(), withRequest: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hub down');

        $hub->publish(new Update('topic-a', 'data'));
    }

    public function testGettersDelegate(): void
    {
        $hub = $this->makeHub($this->workingInnerHub(), withRequest: true);

        self::assertSame('https://hub.example.test/.well-known/mercure', $hub->getPublicUrl());
        self::assertNull($hub->getFactory());
    }

    private function makeHub(HubInterface $inner, bool $withRequest): FailSafeHub
    {
        $requestStack = new RequestStack();
        if ($withRequest) {
            $requestStack->push(Request::create('/api/products', 'POST'));
        }

        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable | string $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        return new FailSafeHub($inner, $logger, $requestStack);
    }

    private function workingInnerHub(): HubInterface
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturn('event-id');
        $hub->method('getPublicUrl')->willReturn('https://hub.example.test/.well-known/mercure');
        $hub->method('getFactory')->willReturn(null);

        return $hub;
    }

    private function brokenInnerHub(): HubInterface
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new RuntimeException('hub down'));

        return $hub;
    }
}
