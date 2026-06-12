<?php

declare(strict_types=1);

use Nubit\AdminBundle\Controller\ChangePasswordController;
use Nubit\AdminBundle\Controller\LoginController;
use Nubit\AdminBundle\Controller\LogoutController;
use Nubit\AdminBundle\Controller\RefreshController;
use Nubit\AdminBundle\Audit\Controller\AuditTrailController;
use Nubit\AdminBundle\Media\Controller\MediaFileController;
use Nubit\AdminBundle\Media\Controller\MediaUploadController;
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

    $routes->add('nubit_admin_auth_change_password', '/api/auth/change-password')
        ->controller(ChangePasswordController::class)
        ->methods(['POST']);

    // Media library (only functional with nubit_admin.media.enabled).
    $routes->add('nubit_admin_media_upload', '/api/media')
        ->controller(MediaUploadController::class)
        ->methods(['POST']);

    $routes->add('nubit_admin_media_file', '/api/media/{id}/file')
        ->controller(MediaFileController::class)
        ->methods(['GET']);

    // Audit trail (only functional with nubit_admin.audit.enabled).
    $routes->add('nubit_admin_audit_trail', '/api/audit-trail/{resource}/{id}')
        ->controller(AuditTrailController::class)
        ->methods(['GET']);
};
