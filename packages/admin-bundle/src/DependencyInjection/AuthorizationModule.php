<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use LogicException;
use Nubit\AdminBundle\Authorization\Entity\Role;
use Nubit\AdminBundle\Authorization\PermissionCatalog;
use Nubit\AdminBundle\Authorization\PermissionResolver;
use Nubit\AdminBundle\Authorization\PermissionSecurityMetadataFactory;
use Nubit\AdminBundle\Authorization\PermissionVoter;
use Nubit\AdminBundle\Authorization\RowScopeExtension;
use Nubit\AdminBundle\Command\PermissionListCommand;
use Nubit\AdminBundle\Security\UnguardedOperationScanner;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class AuthorizationModule
{
    private function __construct() {}

    /**
     * @param array{
     *     enabled: bool,
     *     enforce_by_default: bool,
     *     super_roles: list<string>,
     *     exempt_resources: list<string>,
     * } $config
     */
    public static function load(array $config, DefaultsConfigurator $services): void
    {
        $services->set(PermissionCatalog::class);

        $services->set(PermissionResolver::class)->arg('$superRoles', $config['super_roles'])->arg(
            '$catalog',
            service(PermissionCatalog::class),
        );

        $services->set(PermissionVoter::class)->tag('security.voter');

        // Row scope is applied inside the query, for both collections and items:
        // restricting only the list leaves every hidden row one guessed
        // identifier away.
        $services->set(RowScopeExtension::class)->tag('api_platform.doctrine.orm.query_extension.collection')->tag(
            'api_platform.doctrine.orm.query_extension.item',
        );

        if ($config['enforce_by_default']) {
            // The generated `security:` expressions are evaluated by API
            // Platform through the expression language. Without it every guarded
            // operation throws on its first request — a failure that reaches
            // production because nothing exercises it at boot. Better to refuse
            // to compile.
            if (!class_exists(ExpressionLanguage::class)) {
                throw new LogicException(
                    'nubit_admin.authorization.enforce_by_default derives security expressions, which need '
                    . 'symfony/expression-language. Install it, or set enforce_by_default: false and guard '
                    . 'operations by hand.',
                );
            }

            // Priority above the resource attribute factory so the inferred
            // expression is added after everything declared has been read, and
            // therefore only where nothing was declared.
            $services
                ->set(PermissionSecurityMetadataFactory::class)
                ->decorate('api_platform.metadata.resource.metadata_collection_factory', priority: -10)
                ->arg('$decorated', service('.inner'))
                // The Role resource guards itself with ROLE_ADMIN; deriving a
                // `role.update` permission for it would let a role grant itself
                // the right to edit roles.
                ->arg('$exempt', [...$config['exempt_resources'], Role::class]);
        }

        $services->set(PermissionListCommand::class)->tag('console.command');

        // With permissions in play the audit widens: every operation, not only
        // the mutating ones, has a permission it could have declared, so a
        // read nobody guarded becomes a real finding.
        $services->set(UnguardedOperationScanner::class)->arg(
            '$requirePermissionOnReads',
            $config['enforce_by_default'],
        );
    }
}
