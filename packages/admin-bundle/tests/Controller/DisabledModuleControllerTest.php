<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Controller;

use Nubit\AdminBundle\Controller\DisabledModuleController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DisabledModuleControllerTest extends TestCase
{
    public function testInvokeIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        (new DisabledModuleController())();
    }

    public function testNamedActionsAreNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        (new DisabledModuleController())->__call('forgotPassword', []);
    }
}
