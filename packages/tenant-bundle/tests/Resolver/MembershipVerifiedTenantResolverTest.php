<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Resolver;

use Nubit\TenantBundle\Contract\TenantAwareUserInterface;
use Nubit\TenantBundle\Resolver\MembershipVerifiedTenantResolver;
use Nubit\TenantBundle\Resolver\ResolvedTenant;
use Nubit\TenantBundle\Resolver\TenantResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

final class MembershipVerifiedTenantResolverTest extends TestCase
{
    public function testAnonymousCallerPassesThroughUnverified(): void
    {
        $resolver = new MembershipVerifiedTenantResolver($this->innerResolving(new ResolvedTenant(7)));

        $tenant = $resolver->resolve(new Request(), null);

        self::assertNotNull($tenant);
        self::assertSame(7, $tenant->id);
    }

    public function testNoTenantResolvedPassesThroughUnverified(): void
    {
        $resolver = new MembershipVerifiedTenantResolver($this->innerResolving(null));

        self::assertNull($resolver->resolve(new Request(), $this->tenantAwareUser(3)));
    }

    public function testMemberOfTheClaimedTenantIsAllowed(): void
    {
        $resolver = new MembershipVerifiedTenantResolver($this->innerResolving(new ResolvedTenant(3)));

        $tenant = $resolver->resolve(new Request(), $this->tenantAwareUser(3));

        self::assertNotNull($tenant);
        self::assertSame(3, $tenant->id);
    }

    public function testNonMemberIsRejected(): void
    {
        $resolver = new MembershipVerifiedTenantResolver($this->innerResolving(new ResolvedTenant(9)));

        $this->expectException(AccessDeniedHttpException::class);

        $resolver->resolve(new Request(), $this->tenantAwareUser(3));
    }

    public function testPrivilegedRoleOverridesMembership(): void
    {
        $resolver = new MembershipVerifiedTenantResolver(
            $this->innerResolving(new ResolvedTenant(9)),
            ['ROLE_SUPER_ADMIN'],
        );

        $tenant = $resolver->resolve(new Request(), $this->plainUser(['ROLE_SUPER_ADMIN']));

        self::assertNotNull($tenant);
        self::assertSame(9, $tenant->id);
    }

    public function testAuthenticatedNonTenantAwareUserWithoutPrivilegeIsRejected(): void
    {
        $resolver = new MembershipVerifiedTenantResolver($this->innerResolving(new ResolvedTenant(9)));

        $this->expectException(AccessDeniedHttpException::class);

        $resolver->resolve(new Request(), $this->plainUser(['ROLE_USER']));
    }

    private function innerResolving(?ResolvedTenant $tenant): TenantResolverInterface
    {
        return new class($tenant) implements TenantResolverInterface {
            public function __construct(
                private ?ResolvedTenant $tenant,
            ) {}

            public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
            {
                return $this->tenant;
            }
        };
    }

    private function tenantAwareUser(int $tenantId): UserInterface&TenantAwareUserInterface
    {
        return new readonly class($tenantId) implements UserInterface, TenantAwareUserInterface {
            public function __construct(
                private int $tenantId,
            ) {}

            public function getTenantId(): ?int
            {
                return $this->tenantId;
            }

            public function getTenantName(): ?string
            {
                return null;
            }

            public function getTenantDomain(): ?string
            {
                return null;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void {}

            public function getUserIdentifier(): string
            {
                return 'member@example.com';
            }
        };
    }

    /** @param list<string> $roles */
    private function plainUser(array $roles): UserInterface
    {
        return new readonly class($roles) implements UserInterface {
            public function __construct(
                /** @var list<string> */
                private array $roles,
            ) {}

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function eraseCredentials(): void {}

            public function getUserIdentifier(): string
            {
                return 'plain@example.com';
            }
        };
    }
}
