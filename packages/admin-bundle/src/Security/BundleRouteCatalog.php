<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Security;

use Nubit\AdminBundle\Audit\Controller\AuditTrailController;
use Nubit\AdminBundle\Auth\Oidc\Controller\OidcCallbackController;
use Nubit\AdminBundle\Auth\Oidc\Controller\OidcRedirectController;
use Nubit\AdminBundle\Document\Controller\DocumentHistoryController;
use Nubit\AdminBundle\Document\Controller\DownloadDocumentController;
use Nubit\AdminBundle\Document\Controller\IssueDocumentController;
use Nubit\AdminBundle\Export\Controller\ExportJobController;
use Nubit\AdminBundle\Identity\Controller\IdentityController;
use Nubit\AdminBundle\Import\Controller\ImportController;
use Nubit\AdminBundle\Media\Controller\MediaFileController;
use Nubit\AdminBundle\Media\Controller\MediaUploadController;

/**
 * Bundle routes that are not API Platform operations.
 *
 * `UnguardedOperationScanner` only sees `#[ApiResource]`. These routes are the
 * rest of the HTTP surface, and the reason a module being off used to 500:
 * they are declared in `config/routes.php` regardless of configuration.
 *
 * @phpstan-type BundleController class-string
 */
final class BundleRouteCatalog
{
    /**
     * Controller classes registered only when the named module is on.
     *
     * @return array<string, list<class-string>>
     */
    public static function controllersByModule(): array
    {
        return [
            'identity' => [IdentityController::class],
            'documents' => [
                DownloadDocumentController::class,
                IssueDocumentController::class,
                DocumentHistoryController::class,
            ],
            'imports' => [ImportController::class],
            'media' => [MediaUploadController::class, MediaFileController::class],
            'oidc' => [OidcRedirectController::class, OidcCallbackController::class],
            'audit' => [AuditTrailController::class],
            'export_queued' => [ExportJobController::class],
        ];
    }

    /**
     * Mutating bundle routes, for `nubit:security:audit`.
     *
     * The gate is what the controller actually enforces on top of the
     * firewall's ROLE_USER. Public routes (password reset, invitation accept)
     * are omitted: they are supposed to be reachable without a session.
     *
     * @return list<array{name: string, method: string, path: string, gate: string, module: string}>
     */
    public static function mutatingRoutes(): array
    {
        return [
            [
                'name' => 'nubit_admin_invite',
                'method' => 'POST',
                'path' => '/api/invitations',
                'gate' => 'ROLE_ADMIN',
                'module' => 'identity',
            ],
            [
                'name' => 'nubit_admin_api_key_create',
                'method' => 'POST',
                'path' => '/api/api-keys',
                'gate' => 'owner or ROLE_ADMIN',
                'module' => 'identity',
            ],
            [
                'name' => 'nubit_admin_api_key_rotate',
                'method' => 'POST',
                'path' => '/api/api-keys/{id}/rotate',
                'gate' => 'owner or ROLE_ADMIN',
                'module' => 'identity',
            ],
            [
                'name' => 'nubit_admin_api_key_revoke',
                'method' => 'DELETE',
                'path' => '/api/api-keys/{id}',
                'gate' => 'owner or ROLE_ADMIN',
                'module' => 'identity',
            ],
            [
                'name' => 'nubit_admin_document_issue',
                'method' => 'POST',
                'path' => '/api/documents/{resource}/{id}',
                'gate' => 'ROLE_USER (printable resource)',
                'module' => 'documents',
            ],
            [
                'name' => 'nubit_admin_export_request',
                'method' => 'POST',
                'path' => '/api/exports/{resource}',
                'gate' => 'ROLE_USER (owner-scoped job)',
                'module' => 'export_queued',
            ],
            [
                'name' => 'nubit_admin_import_start',
                'method' => 'POST',
                'path' => '/api/imports/{resource}',
                'gate' => 'ROLE_USER (owner-scoped session)',
                'module' => 'imports',
            ],
            [
                'name' => 'nubit_admin_import_confirm',
                'method' => 'POST',
                'path' => '/api/imports/{id}/confirm',
                'gate' => 'owner or ROLE_ADMIN',
                'module' => 'imports',
            ],
            [
                'name' => 'nubit_admin_import_remap',
                'method' => 'PATCH',
                'path' => '/api/imports/{id}',
                'gate' => 'owner or ROLE_ADMIN',
                'module' => 'imports',
            ],
            [
                'name' => 'nubit_admin_media_upload',
                'method' => 'POST',
                'path' => '/api/media',
                'gate' => 'ROLE_USER',
                'module' => 'media',
            ],
            [
                'name' => 'nubit_admin_totp_begin',
                'method' => 'POST',
                'path' => '/api/auth/totp',
                'gate' => 'authenticated self',
                'module' => 'identity',
            ],
            [
                'name' => 'nubit_admin_totp_disable',
                'method' => 'DELETE',
                'path' => '/api/auth/totp',
                'gate' => 'authenticated self + TOTP code',
                'module' => 'identity',
            ],
            [
                'name' => 'nubit_admin_session_revoke',
                'method' => 'DELETE',
                'path' => '/api/auth/sessions/{id}',
                'gate' => 'authenticated self',
                'module' => 'identity',
            ],
        ];
    }
}
