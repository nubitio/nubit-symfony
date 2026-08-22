<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use Nubit\AdminBundle\Identity\ApiKeyAuthenticator;
use Nubit\AdminBundle\Identity\ApiKeyManager;
use Nubit\AdminBundle\Identity\AttemptLimiter;
use Nubit\AdminBundle\Identity\Controller\IdentityController;
use Nubit\AdminBundle\Identity\DoctrineIdentityUserGateway;
use Nubit\AdminBundle\Identity\EventListener\TotpListener;
use Nubit\AdminBundle\Identity\IdentityTokenStore;
use Nubit\AdminBundle\Identity\IdentityUserGatewayInterface;
use Nubit\AdminBundle\Identity\InvitationService;
use Nubit\AdminBundle\Identity\PasswordResetService;
use Nubit\AdminBundle\Identity\SessionRegistry;
use Nubit\AdminBundle\Identity\TotpManager;
use Nubit\AdminBundle\Identity\TotpPolicy;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class IdentityModule
{
    private function __construct() {}

    /**
     * @param array{
     *     enabled: bool,
     *     issuer: string,
     *     totp: array{required_for_all: bool, required_for_roles: list<string>},
     *     password_reset: array{lifetime_minutes: int, max_attempts: int, window_seconds: int},
     *     invitations: array{lifetime_days: int},
     *     user_class: ?string,
     *     user_identifier_property: string,
     * } $config
     */
    public static function load(array $config, DefaultsConfigurator $services): void
    {
        $services->set(TotpPolicy::class)->arg('$requiredForAll', $config['totp']['required_for_all'])->arg(
            '$requiredForRoles',
            $config['totp']['required_for_roles'],
        );

        $services->set(TotpManager::class)->arg('$issuer', $config['issuer']);
        $services->set(TotpListener::class)->tag('kernel.event_subscriber');

        $services->set(IdentityTokenStore::class);

        // The gateway is only registered when the application named its User
        // class. Without one, password reset and invitations have nothing to
        // write to, and failing at compile time is better than at the moment a
        // locked-out user clicks a link.
        if (null !== $config['user_class']) {
            $services->set(DoctrineIdentityUserGateway::class)->arg('$userClass', $config['user_class'])->arg(
                '$identifierProperty',
                $config['user_identifier_property'],
            );
            $services->alias(IdentityUserGatewayInterface::class, DoctrineIdentityUserGateway::class);
        }

        $services->set(AttemptLimiter::class)->arg('$cache', service('cache.app'))->arg(
            '$limit',
            $config['password_reset']['max_attempts'],
        )->arg('$windowSeconds', $config['password_reset']['window_seconds']);

        $services->set(PasswordResetService::class)->arg(
            '$lifetimeMinutes',
            $config['password_reset']['lifetime_minutes'],
        );

        $services->set(InvitationService::class)->arg('$lifetimeDays', $config['invitations']['lifetime_days']);

        $services->set(ApiKeyManager::class);
        $services->set(ApiKeyAuthenticator::class);

        $services->set(SessionRegistry::class);

        $services->set(IdentityController::class)->tag('controller.service_arguments');
    }
}
