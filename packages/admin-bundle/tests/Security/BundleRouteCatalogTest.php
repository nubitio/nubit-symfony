<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Security;

use Nubit\AdminBundle\Identity\Controller\IdentityController;
use Nubit\AdminBundle\Security\BundleRouteCatalog;
use PHPUnit\Framework\TestCase;

final class BundleRouteCatalogTest extends TestCase
{
    public function testEveryModuleNamesAtLeastOneController(): void
    {
        foreach (BundleRouteCatalog::controllersByModule() as $module => $classes) {
            static::assertNotSame([], $classes, $module);
            foreach ($classes as $class) {
                static::assertTrue(class_exists($class), $class);
            }
        }

        static::assertContains(IdentityController::class, BundleRouteCatalog::controllersByModule()['identity']);
    }

    public function testMutatingRoutesNameAKnownModule(): void
    {
        $modules = array_keys(BundleRouteCatalog::controllersByModule());

        foreach (BundleRouteCatalog::mutatingRoutes() as $route) {
            static::assertContains($route['module'], $modules, $route['name']);
            static::assertNotSame('', $route['gate']);
        }
    }
}
