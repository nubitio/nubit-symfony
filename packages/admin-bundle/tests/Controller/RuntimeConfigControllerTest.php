<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Controller;

use Nubit\AdminBundle\Controller\RuntimeConfigController;
use Nubit\AdminBundle\Runtime\NullRuntimeConfigProvider;
use Nubit\AdminBundle\Runtime\RuntimeConfigProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RuntimeConfigControllerTest extends TestCase
{
    public function testThrowsWhenRuntimeConfigIsDisabled(): void
    {
        $controller = new RuntimeConfigController(false, new NullRuntimeConfigProvider());

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Runtime config is not enabled.');

        $controller->__invoke();
    }

    public function testReturnsProviderPayloadWhenEnabled(): void
    {
        $provider = new class implements RuntimeConfigProviderInterface {
            public function getConfig(): array
            {
                return ['ui' => ['density' => 'compact']];
            }
        };

        $controller = new RuntimeConfigController(true, $provider);
        $response = $controller->__invoke();

        self::assertSame('{"ui":{"density":"compact"}}', $response->getContent());
    }
}