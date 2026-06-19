<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Session;

use Nubit\AdminBundle\Session\AppProfile;
use Nubit\AdminBundle\Session\DefaultMeResponseBuilder;
use Nubit\AdminBundle\Tenant\AllowAllFeatureChecker;
use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class DefaultMeResponseBuilderTest extends TestCase
{
    public function testInternalProfileOmitsTenantAndFeatures(): void
    {
        $tenantContext = new TenantContext();
        $tenantContext->setTenant(1, 'acme', 'acme.test', null);

        $builder = new DefaultMeResponseBuilder(
            AppProfile::Internal,
            $tenantContext,
            new AllowAllFeatureChecker(),
        );

        $response = $builder->build($this->user('admin@example.com', ['ROLE_ADMIN']));

        self::assertSame([
            'username' => 'admin@example.com',
            'roles' => ['ROLE_ADMIN'],
            'appProfile' => 'internal',
        ], $response);
    }

    public function testSaasProfileIncludesTenantWhenContextIsSet(): void
    {
        $tenantContext = new TenantContext();
        $tenantContext->setTenant(42, 'acme', 'acme.test', null);

        $builder = new DefaultMeResponseBuilder(
            AppProfile::Saas,
            $tenantContext,
            new AllowAllFeatureChecker(),
        );

        $response = $builder->build($this->user('jane@example.com', ['ROLE_USER']));

        self::assertSame('saas', $response['appProfile']);
        self::assertSame([
            'id' => 42,
            'name' => 'acme',
            'domain' => 'acme.test',
        ], $response['tenant']);
        self::assertArrayNotHasKey('features', $response);
    }

    public function testSaasProfileOmitsTenantWhenContextIsEmpty(): void
    {
        $builder = new DefaultMeResponseBuilder(
            AppProfile::Saas,
            new TenantContext(),
            new AllowAllFeatureChecker(),
        );

        $response = $builder->build($this->user('jane@example.com', ['ROLE_USER']));

        self::assertSame('saas', $response['appProfile']);
        self::assertArrayNotHasKey('tenant', $response);
    }

    public function testSaasProfileIncludesFeatureEntitlements(): void
    {
        $featureChecker = new class implements FeatureCheckerInterface {
            public function hasFeature(string $featureKey): bool
            {
                return true;
            }

            public function getFeatureConfig(string $featureKey): array
            {
                return [];
            }

            public function requireFeature(string $featureKey): void
            {
            }

            public function getEntitlements(): array
            {
                return [
                    'reports' => ['enabled' => true, 'config' => ['max' => 10]],
                ];
            }
        };

        $builder = new DefaultMeResponseBuilder(
            AppProfile::Saas,
            null,
            $featureChecker,
        );

        $response = $builder->build($this->user('jane@example.com', ['ROLE_USER']));

        self::assertSame([
            'reports' => ['enabled' => true, 'config' => ['max' => 10]],
        ], $response['features']);
    }

    public function testHybridProfileIncludesTenantBlock(): void
    {
        $tenantContext = new TenantContext();
        $tenantContext->setTenant(7, 'hq', null, null);

        $builder = new DefaultMeResponseBuilder(AppProfile::Hybrid, $tenantContext);

        $response = $builder->build($this->user('ops@example.com', ['ROLE_USER']));

        self::assertSame('hybrid', $response['appProfile']);
        self::assertSame([
            'id' => 7,
            'name' => 'hq',
        ], $response['tenant']);
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $identifier, array $roles): UserInterface
    {
        return new readonly class ($identifier, $roles) implements UserInterface {
            public function __construct(
                private string $identifier,
                private array $roles,
            ) {
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return $this->identifier;
            }
        };
    }
}