<?php

declare(strict_types=1);

use Nubit\AdminBundle\Controller\LoginController;
use Nubit\AdminBundle\Controller\LogoutController;
use Nubit\AdminBundle\Controller\RefreshController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('nubit_admin_auth_login', '/api/auth/login')
        ->controller(LoginController::class)
        ->methods(['POST']);

    $routes->add('nubit_admin_auth_refresh', '/api/auth/refresh')
        ->controller(RefreshController::class)
        ->methods(['POST']);

    $routes->add('nubit_admin_auth_logout', '/api/auth/logout')
        ->controller(LogoutController::class)
        ->methods(['POST']);
};
