<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Resolver;

use Nubit\TenantBundle\Contract\TenantAwareUserInterface;
use Nubit\TenantBundle\Resolver\UserTenantResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserTenantResolverTest extends TestCase
{
    public function testResolvesTenantFromAwareUser(): void
    {
        $resolver = new UserTenantResolver();
        $tenant = $resolver->resolve(new Request(), new TenantAwareUserStub(7, 'hq', 'hq.example.test'));

        self::assertNotNull($tenant);
        self::assertSame(7, $tenant->id);
        self::assertSame('hq', $tenant->name);
        self::assertSame('hq.example.test', $tenant->domain);
    }

    public function testSkipsRegularUsers(): void
    {
        $resolver = new UserTenantResolver();

        self::assertNull($resolver->resolve(new Request(), new readonly class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'user';
            }
        }));
    }
}

final readonly class TenantAwareUserStub implements TenantAwareUserInterface, UserInterface
{
    public function __construct(
        private int $tenantId,
        private string $tenantName,
        private ?string $tenantDomain,
    ) {
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function getTenantName(): ?string
    {
        return $this->tenantName;
    }

    public function getTenantDomain(): ?string
    {
        return $this->tenantDomain;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'tenant-user';
    }
}