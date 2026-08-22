<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\AdminBundle;

use Nubit\AdminBundle\Analytics\Entity\AnalyticsOutboxEntry;
use Nubit\AdminBundle\Audit\Entity\AuditLog;
use Nubit\AdminBundle\Entity\RefreshToken;
use Nubit\AdminBundle\Media\Entity\Media;
use Nubit\AdminBundle\Notification\EmailNotificationChannel;
use Nubit\AdminBundle\Notification\Entity\Notification;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every optional module has to survive being both on and off.
 *
 * Off is the harder case and the one that regressed before: a module that maps
 * its entities regardless of configuration puts tables into the schema of every
 * application generated from the skeleton, which then has to migrate them away.
 * A unit test on the extension class cannot see that — the mapping is decided
 * during container compilation, by prependExtension, against the real Doctrine
 * extension.
 */
#[CoversNothing]
final class ModuleWiringTest extends IntegrationTestCase
{
    /** Module key => the entity it must map only when enabled. */
    private const array MODULE_ENTITIES = [
        'audit' => AuditLog::class,
        'media' => Media::class,
        'notification' => Notification::class,
        'analytics' => AnalyticsOutboxEntry::class,
    ];

    /** @return iterable<string, array{string}> */
    public static function moduleProvider(): iterable
    {
        foreach (array_keys(self::MODULE_ENTITIES) as $module) {
            yield $module => [$module];
        }
    }

    #[DataProvider('moduleProvider')]
    public function testModuleMapsItsEntityOnlyWhenEnabled(string $module): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => $this->config([$module => true]),
            ],
            self::fixtureMapping(),
        );

        self::assertTrue(
            $this->isMapped(self::MODULE_ENTITIES[$module]),
            sprintf('Module "%s" is enabled but its entity is not mapped.', $module),
        );

        $this->kernel?->shutdown();

        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => $this->config([$module => false]),
            ],
            self::fixtureMapping(),
        );

        self::assertFalse(
            $this->isMapped(self::MODULE_ENTITIES[$module]),
            sprintf(
                'Module "%s" is disabled yet its entity is still mapped: every application built on the '
                . 'skeleton would inherit the table.',
                $module,
            ),
        );
    }

    /** All modules on at once — the combination applications actually grow into. */
    public function testEveryModuleEnabledTogetherCompiles(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => $this->config(array_fill_keys(array_keys(self::MODULE_ENTITIES), true)),
            ],
            self::fixtureMapping(),
        );

        foreach (self::MODULE_ENTITIES as $module => $entity) {
            self::assertTrue($this->isMapped($entity), sprintf('Module "%s" did not map %s.', $module, $entity));
        }

        // The schema has to be creatable, not merely mappable: a module whose
        // mapping compiles but whose DDL collides is still broken.
        $this->resetSchema();
    }

    /**
     * symfony/mailer is routinely present as a transitive dependency of
     * something else while `framework.mailer` is never configured. Before the
     * compiler pass, enabling notifications in that situation failed container
     * compilation with an autowiring error that never mentioned notifications.
     */
    public function testNotificationsBootWithoutAConfiguredMailer(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => $this->config(['notification' => true]),
            ],
            self::fixtureMapping(),
        );

        self::assertFalse(
            $this->container()->has(EmailNotificationChannel::class),
            'The email channel must be dropped when no mailer service exists.',
        );
    }

    public function testEmailChannelIsRegisteredWhenAMailerExists(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'framework' => ['mailer' => ['dsn' => 'null://null']],
                'nubit_admin' => $this->config(['notification' => true]),
            ],
            self::fixtureMapping(),
        );

        self::assertTrue($this->container()->has(EmailNotificationChannel::class));
    }

    /** Auth is not optional, so its refresh token store must always be present. */
    public function testRefreshTokenIsMappedWithEveryModuleOff(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => $this->config(array_fill_keys(array_keys(self::MODULE_ENTITIES), false)),
            ],
            self::fixtureMapping(),
        );

        self::assertTrue($this->isMapped(RefreshToken::class));
    }

    /**
     * @param array<string, bool> $modules
     *
     * @return array<string, mixed>
     */
    private function config(array $modules): array
    {
        $config = [
            'app_profile' => 'internal',
            'auth' => ['secret' => '%env(APP_SECRET)%'],
        ];

        foreach ($modules as $module => $enabled) {
            $config[$module] = ['enabled' => $enabled];
        }

        // The Notification entity hangs off the nested in-app toggle, not off
        // the module root: notifications can be enabled for email delivery
        // alone, and then no table is wanted.
        if (isset($config['notification'])) {
            $config['notification']['in_app'] = ['enabled' => $config['notification']['enabled']];
        }

        return $config;
    }

    private function isMapped(string $entityClass): bool
    {
        return $this->entityManager()->getMetadataFactory()->hasMetadataFor($entityClass);
    }
}
