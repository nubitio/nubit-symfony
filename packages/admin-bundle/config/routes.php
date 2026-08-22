<?php

declare(strict_types=1);

use Nubit\AdminBundle\Auth\Oidc\Controller\OidcCallbackController;
use Nubit\AdminBundle\Auth\Oidc\Controller\OidcRedirectController;
use Nubit\AdminBundle\Auth\Oidc\OidcAuthenticator;
use Nubit\AdminBundle\Controller\ChangePasswordController;
use Nubit\AdminBundle\Document\Controller\DocumentHistoryController;
use Nubit\AdminBundle\Document\Controller\DownloadDocumentController;
use Nubit\AdminBundle\Document\Controller\IssueDocumentController;
use Nubit\AdminBundle\Export\Controller\ExportJobController;
use Nubit\AdminBundle\Identity\Controller\IdentityController;
use Nubit\AdminBundle\Import\Controller\ImportController;
use Nubit\AdminBundle\Controller\LoginController;
use Nubit\AdminBundle\Controller\LogoutController;
use Nubit\AdminBundle\Controller\MeController;
use Nubit\AdminBundle\Controller\RefreshController;
use Nubit\AdminBundle\Controller\RuntimeConfigController;
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

    $routes->add('nubit_admin_me', '/api/me')
        ->controller(MeController::class)
        ->methods(['GET']);

    // Runtime config (only functional with nubit_admin.runtime_config.enabled).
    $routes->add('nubit_admin_runtime_config', '/api/runtime-config')
        ->controller(RuntimeConfigController::class)
        ->methods(['GET']);

    // Issued documents (only functional with nubit_admin.documents.enabled).
    // The history route is declared before the download route so
    // /api/documents/{id}/file is not swallowed by {resource}/{id}.
    $routes->add('nubit_admin_document_download', '/api/documents/{id}/file')
        ->controller(DownloadDocumentController::class)
        ->methods(['GET']);

    $routes->add('nubit_admin_document_issue', '/api/documents/{resource}/{id}')
        ->controller(IssueDocumentController::class)
        ->methods(['POST']);

    $routes->add('nubit_admin_document_history', '/api/documents/{resource}/{id}')
        ->controller(DocumentHistoryController::class)
        ->methods(['GET']);

    // Identity lifecycle (only functional with nubit_admin.identity.enabled).
    // The public routes — forgot, reset, invitation preview and acceptance —
    // must be added to access_control as PUBLIC_ACCESS: whoever needs them is
    // by definition unable to sign in.
    $routes->add('nubit_admin_password_forgot', '/api/auth/password/forgot')
        ->controller([IdentityController::class, 'forgotPassword'])
        ->methods(['POST']);

    $routes->add('nubit_admin_password_reset', '/api/auth/password/reset')
        ->controller([IdentityController::class, 'resetPassword'])
        ->methods(['POST']);

    $routes->add('nubit_admin_totp_status', '/api/auth/totp')
        ->controller([IdentityController::class, 'totpStatus'])
        ->methods(['GET']);

    $routes->add('nubit_admin_totp_begin', '/api/auth/totp')
        ->controller([IdentityController::class, 'totpBegin'])
        ->methods(['POST']);

    $routes->add('nubit_admin_totp_disable', '/api/auth/totp')
        ->controller([IdentityController::class, 'totpDisable'])
        ->methods(['DELETE']);

    $routes->add('nubit_admin_totp_confirm', '/api/auth/totp/confirm')
        ->controller([IdentityController::class, 'totpConfirm'])
        ->methods(['POST']);

    $routes->add('nubit_admin_totp_recovery_codes', '/api/auth/totp/recovery-codes')
        ->controller([IdentityController::class, 'totpRecoveryCodes'])
        ->methods(['POST']);

    $routes->add('nubit_admin_sessions', '/api/auth/sessions')
        ->controller([IdentityController::class, 'listSessions'])
        ->methods(['GET']);

    $routes->add('nubit_admin_session_revoke', '/api/auth/sessions/{id}')
        ->controller([IdentityController::class, 'revokeSession'])
        ->methods(['DELETE']);

    $routes->add('nubit_admin_invite', '/api/invitations')
        ->controller([IdentityController::class, 'invite'])
        ->methods(['POST']);

    $routes->add('nubit_admin_invitation_preview', '/api/invitations/{token}')
        ->controller([IdentityController::class, 'previewInvitation'])
        ->methods(['GET']);

    $routes->add('nubit_admin_invitation_accept', '/api/invitations/{token}/accept')
        ->controller([IdentityController::class, 'acceptInvitation'])
        ->methods(['POST']);

    $routes->add('nubit_admin_api_keys', '/api/api-keys')
        ->controller([IdentityController::class, 'listApiKeys'])
        ->methods(['GET']);

    $routes->add('nubit_admin_api_key_create', '/api/api-keys')
        ->controller([IdentityController::class, 'createApiKey'])
        ->methods(['POST']);

    $routes->add('nubit_admin_api_key_rotate', '/api/api-keys/{id}/rotate')
        ->controller([IdentityController::class, 'rotateApiKey'])
        ->methods(['POST']);

    $routes->add('nubit_admin_api_key_revoke', '/api/api-keys/{id}')
        ->controller([IdentityController::class, 'revokeApiKey'])
        ->methods(['DELETE']);

    // Queued exports (only functional with nubit_admin.export.queued).
    $routes->add('nubit_admin_exports', '/api/exports')
        ->controller([ExportJobController::class, 'list'])
        ->methods(['GET']);

    $routes->add('nubit_admin_export_download', '/api/exports/{id}/file')
        ->controller([ExportJobController::class, 'download'])
        ->methods(['GET']);

    $routes->add('nubit_admin_export_request', '/api/exports/{resource}')
        ->controller([ExportJobController::class, 'request'])
        ->methods(['POST']);

    $routes->add('nubit_admin_export_show', '/api/exports/{id}')
        ->controller([ExportJobController::class, 'show'])
        ->methods(['GET']);

    // Spreadsheet import (only functional with nubit_admin.imports.enabled).
    $routes->add('nubit_admin_import_start', '/api/imports/{resource}')
        ->controller([ImportController::class, 'start'])
        ->methods(['POST']);

    $routes->add('nubit_admin_import_confirm', '/api/imports/{id}/confirm')
        ->controller([ImportController::class, 'confirm'])
        ->methods(['POST']);

    $routes->add('nubit_admin_import_show', '/api/imports/{id}')
        ->controller([ImportController::class, 'show'])
        ->methods(['GET']);

    $routes->add('nubit_admin_import_remap', '/api/imports/{id}')
        ->controller([ImportController::class, 'remap'])
        ->methods(['PATCH']);

    // Media library (only functional with nubit_admin.media.enabled).
    $routes->add('nubit_admin_media_upload', '/api/media')
        ->controller(MediaUploadController::class)
        ->methods(['POST']);

    $routes->add('nubit_admin_media_file', '/api/media/{id}/file')
        ->controller(MediaFileController::class)
        ->methods(['GET']);

    // OIDC/SSO (only functional with nubit_admin.oidc.enabled).
    $routes->add('nubit_admin_oidc_redirect', '/api/auth/oidc/{provider}/redirect')
        ->controller(OidcRedirectController::class)
        ->methods(['GET']);

    $routes->add(OidcAuthenticator::CALLBACK_ROUTE, '/api/auth/oidc/{provider}/callback')
        ->controller(OidcCallbackController::class)
        ->methods(['GET']);

    // Audit trail (only functional with nubit_admin.audit.enabled).
    $routes->add('nubit_admin_audit_trail', '/api/audit-trail/{resource}/{id}')
        ->controller(AuditTrailController::class)
        ->methods(['GET']);
};
