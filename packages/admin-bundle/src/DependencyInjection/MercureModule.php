<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use Nubit\AdminBundle\Auth\MercureCookieDecorator;
use Nubit\AdminBundle\Auth\MercureSubscriberTokenService;
use Nubit\AdminBundle\Mercure\FailSafeHub;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class MercureModule
{
    private function __construct() {}

    /**
     * @param array{
     *     enabled: bool,
     *     fail_safe: bool,
     *     secret: string,
     *     topics: list<string>,
     *     hub_path: string,
     * } $config
     */
    public static function load(array $config, int $accessTokenTtl, DefaultsConfigurator $services): void
    {
        // Fail-safe hub: independent of mercure.enabled (which only gates the
        // subscriber cookie) — it matters to ANY app with mercure: true
        // resources. class_exists, NOT hasExtension: loadExtension runs in a
        // per-extension temporary container that only knows nubit_admin, so
        // hasExtension is always false here. IGNORE_ON_INVALID_REFERENCE skips
        // the decoration when MercureBundle is installed but no default hub is
        // configured (apps with custom hub names decorate manually).
        if ($config['fail_safe'] && class_exists('Symfony\\Bundle\\MercureBundle\\MercureBundle')) {
            $services->set(FailSafeHub::class)->decorate(
                'mercure.hub.default',
                null,
                0,
                ContainerInterface::IGNORE_ON_INVALID_REFERENCE,
            )->arg('$inner', service('.inner'));
        }

        if ($config['enabled']) {
            $services->set(MercureSubscriberTokenService::class)->arg('$mercureJwtSecret', $config['secret'])->arg(
                '$tokenTtl',
                $accessTokenTtl,
            );
            $services
                ->set(MercureCookieDecorator::class)
                ->arg('$topics', $config['topics'])
                ->arg('$hubPath', $config['hub_path'])
                ->tag('nubit.admin.login_response_decorator');
        }
    }
}
