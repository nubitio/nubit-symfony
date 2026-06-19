<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class CompositeTenantResolver implements TenantResolverInterface
{
    /**
     * @param list<TenantResolverInterface> $resolvers
     */
    public function __construct(
        private array $resolvers,
    ) {
    }

    public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
    {
        foreach ($this->resolvers as $resolver) {
            $tenant = $resolver->resolve($request, $user);
            if (null !== $tenant) {
                return $tenant;
            }
        }

        return null;
    }
}